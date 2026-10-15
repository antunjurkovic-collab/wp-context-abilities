"""Disposable real-WordPress HTTP regression test; no hosted target argument."""
import argparse
import base64
import hashlib
import http.client
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import time
import urllib.parse
import uuid


ROOT = Path(__file__).resolve().parents[1]
WP_IMAGE = "wordpress@sha256:da3e22820653253e5f7074bc4a3603c2f9cbe571e1766b13a517e355f125a0d8"
DB_IMAGE = "mariadb@sha256:a794d9eb009e20de605858a11f32f63b4075cbd197c650436f0e3b457e4caed7"
RUNTIME = ["wp-context-abilities.php"] + [f"includes/class-wpca-{name}.php" for name in ("abilities", "cid", "mr", "rest")]


def sha(data):
    return hashlib.sha256(data).hexdigest()


def docker(*args, env=None, timeout=90):
    result = subprocess.run(["docker", *args], capture_output=True, text=True,
                            encoding="utf-8", errors="replace", env=env, timeout=timeout)
    if result.returncode:
        # Do not retain native output: setup stdout contains temporary credentials.
        raise RuntimeError("docker_" + args[0] + "_failed")
    return result.stdout.strip()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--editorial-comparison", action="store_true",
                        help="Compare one synthetic draft against an optimized Core REST request")
    args = parser.parse_args()
    if args.output.exists():
        raise SystemExit("Refusing to overwrite retained test output")
    prefix = "wpca-http-" + uuid.uuid4().hex[:12]
    network, database, web = prefix, prefix + "-db", prefix + "-wp"
    volumes = [prefix + "-database", prefix + "-wordpress"]
    checks = []
    report = {"scope": "Disposable Docker WordPress HTTP regression; no hosted or model-effectiveness claim.",
              "images": {"wordpress": WP_IMAGE, "database": DB_IMAGE}, "results": checks}
    if args.editorial_comparison:
        report["scope"] = "Disposable synthetic editorial context comparison; retrieval measurements only."
    before = {name: sha((ROOT / name).read_bytes()) for name in RUNTIME}
    report["runtime_sha256"] = before
    report["test_sha256"] = {name: sha((ROOT / "tests" / name).read_bytes()) for name in
                              ("run-docker.py", "docker-fixtures.php", "run-regressions.php", "wordpress-fixture.php")}
    if args.editorial_comparison:
        report["test_sha256"]["editorial_comparison.py"] = sha((ROOT / "tests/editorial_comparison.py").read_bytes())
    env = os.environ.copy()
    env.update(MARIADB_ROOT_PASSWORD=secrets.token_hex(24), MARIADB_DATABASE="wpca_test",
               MARIADB_USER="wpca_test", MARIADB_PASSWORD=secrets.token_hex(24),
               WORDPRESS_DB_HOST=database, WORDPRESS_DB_NAME="wpca_test", WORDPRESS_DB_USER="wpca_test",
               WPCA_TEST_PASSWORD=secrets.token_hex(24), WPCA_DISPOSABLE_TEST="1")
    env["WORDPRESS_DB_PASSWORD"] = env["MARIADB_PASSWORD"]
    env["WORDPRESS_CONFIG_EXTRA"] = (
        "define('WP_ENVIRONMENT_TYPE', 'local'); define('DISABLE_WP_CRON', true); "
        "define('AUTOMATIC_UPDATER_DISABLED', true); define('WP_HTTP_BLOCK_EXTERNAL', true);")

    def check(name, ok):
        checks.append({"name": name, "ok": bool(ok)})
        print(("PASS " if ok else "FAIL ") + name, flush=True)
        if not ok:
            raise RuntimeError(name)

    def fixture(action):
        return json.loads(docker("exec", "--env", "WPCA_TEST_URL", web, "php",
                                 "/opt/wpca-source/tests/docker-fixtures.php", action, env=env))

    try:
        report["stage"] = "docker_setup"
        for image in (WP_IMAGE, DB_IMAGE):
            docker("image", "inspect", image, "--format", "{{.Id}}")
        # Publish only to loopback; WordPress HTTP egress is disabled.
        docker("network", "create", network)
        for volume in volumes:
            docker("volume", "create", volume)
        docker("run", "--detach", "--pull=never", "--name", database, "--network", network,
               "--memory", "512m", "--cpus", "1", "--mount", f"type=volume,src={volumes[0]},dst=/var/lib/mysql",
               "--env", "MARIADB_ROOT_PASSWORD", "--env", "MARIADB_DATABASE", "--env", "MARIADB_USER",
               "--env", "MARIADB_PASSWORD", "--health-cmd", "healthcheck.sh --connect --innodb_initialized",
               "--health-interval", "2s", "--health-timeout", "5s", "--health-retries", "40", DB_IMAGE, env=env)
        deadline = time.monotonic() + 100
        while docker("inspect", "--format", "{{.State.Health.Status}}", database) != "healthy":
            if time.monotonic() > deadline:
                raise RuntimeError("database_start_timeout")
            time.sleep(2)
        docker("run", "--detach", "--pull=never", "--name", web, "--network", network,
               "--memory", "512m", "--cpus", "1", "--publish", "127.0.0.1::80",
               "--mount", f"type=volume,src={volumes[1]},dst=/var/www/html",
               "--mount", f"type=bind,src={ROOT},dst=/opt/wpca-source,readonly",
               "--env", "WORDPRESS_DB_HOST", "--env", "WORDPRESS_DB_NAME", "--env", "WORDPRESS_DB_USER",
               "--env", "WORDPRESS_DB_PASSWORD", "--env", "WORDPRESS_CONFIG_EXTRA",
               "--env", "WPCA_DISPOSABLE_TEST", "--env", "WPCA_TEST_PASSWORD", WP_IMAGE, env=env)
        address = docker("port", web, "80/tcp")
        if not address.startswith("127.0.0.1:") or "\n" in address:
            raise RuntimeError("non_loopback_binding")
        port = int(address.split(":")[1])
        env["WPCA_TEST_URL"] = f"http://127.0.0.1:{port}"
        deadline = time.monotonic() + 60
        while True:
            ready = subprocess.run(["docker", "exec", web, "test", "-f", "/var/www/html/wp-config.php"],
                                   capture_output=True, timeout=10)
            if ready.returncode == 0:
                break
            if time.monotonic() > deadline:
                raise RuntimeError("wordpress_start_timeout")
            time.sleep(1)
        report["stage"] = "wordpress_fixture_setup"
        setup = fixture("setup")
        report["versions"] = {key: setup[key] for key in ("wordpress", "php", "database")}
        check("installed_runtime_matches_source", setup["runtime_hashes"] == before)
        report["stage"] = "wordpress_fidelity_checks"
        report["fidelity_checks"] = fixture("fidelity-checks")
        check("wordpress_fidelity_checks_pass", report["fidelity_checks"]["ok"])
        if not args.editorial_comparison:
            report["stage"] = "class_regressions"
            unit = json.loads(docker("exec", web, "php", "/opt/wpca-source/tests/run-regressions.php", "/usr/src/wordpress"))
            report["class_regressions"] = unit
            check("class_regressions_pass", unit["ok"] and unit["checks"] == 69)
        credentials, ids = setup["credentials"], setup["ids"]

        def request(route, params=None, role="admin", headers=None, method="GET"):
            query = urllib.parse.urlencode({"rest_route": route, **(params or {})})
            sent = {"Accept-Encoding": "identity", **(headers or {})}
            if role:
                credential = credentials[role]
                pair = credential["user"] + ":" + credential["password"]
                sent["Authorization"] = "Basic " + base64.b64encode(pair.encode()).decode()
            connection = http.client.HTTPConnection("127.0.0.1", port, timeout=20)
            try:
                connection.request(method, "/index.php?" + query, headers=sent)
                response = connection.getresponse()
                body = response.read(2 * 1024 * 1024 + 1)
                if len(body) > 2 * 1024 * 1024:
                    raise RuntimeError("response_too_large")
                return response.status, {k.lower(): v for k, v in response.getheaders()}, body
            finally:
                connection.close()

        def identity(response):
            status, headers, body = response
            digest = hashlib.sha256(body).digest()
            return (status == 200 and headers.get("etag") == '"sha256-' + digest.hex() + '"' and
                    headers.get("content-digest") == "sha-256=:" + base64.b64encode(digest).decode() + ":")

        if args.editorial_comparison:
            from editorial_comparison import compare
            report["stage"] = "editorial_comparison"
            report["comparison"] = compare(request, fixture, check, ids, args.output.parent)
        else:
            post = "/wp-context/v1/posts/" + str(ids["published"])
            own = "/wp-context/v1/posts/" + str(ids["own"])
            other = "/wp-context/v1/posts/" + str(ids["other"])
            report["stage"] = "http_regressions"
            initial_state = fixture("state")
            compact = request(post)
            check("http_compact_bytes_match_etag_and_digest", identity(compact))
            data = json.loads(compact[2])
            check("application_cid_is_not_http_validator", compact[1]["etag"] != '"' + data["cid"] + '"')
            pretty = request(post, {"_pretty": "1"})
            check("http_pretty_bytes_match_etag_and_digest", identity(pretty))
            check("pretty_and_compact_have_same_data_different_validators",
                  json.loads(pretty[2]) == data and pretty[1]["etag"] != compact[1]["etag"])
            for name, validator in (("exact", compact[1]["etag"]), ("weak", "W/" + compact[1]["etag"]),
                                    ("list", '"not-current", ' + compact[1]["etag"]), ("wildcard", "*")):
                response = request(post, headers={"If-None-Match": validator})
                check("http_" + name + "_304_no_body", response[0] == 304 and response[2] == b"" and
                      "content-digest" not in response[1] and response[1].get("etag") == compact[1]["etag"])
            check("compact_validator_cannot_revalidate_pretty", request(post, {"_pretty": "1"},
                  headers={"If-None-Match": compact[1]["etag"]})[0] == 200)
            response = request(post, method="HEAD")
            check("http_head_identity_no_body", response[0] == 200 and response[2] == b"" and
                  response[1].get("etag") == compact[1]["etag"] and "content-digest" not in response[1])
            for wrapper, value in (("_envelope", "1"), ("_jsonp", "fixtureCallback")):
                response = request(post, {wrapper: value})
                check(wrapper + "_has_no_plugin_identity", response[0] == 200 and
                      "etag" not in response[1] and "content-digest" not in response[1])
            response = request(own, role="author")
            check("author_can_read_own_draft", identity(response))
            check("unauthenticated_request_denied", request(own, role=None)[0] == 401)
            check("subscriber_request_denied", request(own, role="subscriber")[0] == 403)
            check("other_author_draft_denied_even_with_wildcard", request(other, role="author",
                  headers={"If-None-Match": "*"})[0] == 403)
            markdown = request(own + "/md", role="author")
            check("http_markdown_bytes_match_identity", identity(markdown))
            text = markdown[2].decode()
            check("markdown_is_text_not_json_string", markdown[1]["content-type"].startswith("text/markdown") and text.startswith("# "))
            check("modern_list_items_preserved_once", "- Alpha\n- Beta\n" in text and text.count("Alpha") == text.count("Beta") == 1)
            check("nested_list_hierarchy_preserved", "- Parent\n  1. Child\n  2. Sibling\n" in text)
            check("legacy_list_items_preserved", "1. Legacy one\n2. Legacy two\n" in text)
            check("heading_preserved", "## Review heading" in text)
            check("markdown_304", request(own + "/md", role="author", headers={"If-None-Match": markdown[1]["etag"]})[0] == 304)
            pages = []
            for page in (1, 2, 3):
                response = request("/wp-context/v1/catalog", {"status": "draft", "types": "post", "per_page": 1, "page": page}, role="author")
                check("catalog_page_" + str(page) + "_identity", identity(response))
                pages.append(json.loads(response[2]))
            check("empty_permission_filtered_page_continues", pages[0]["items"] == [] and pages[0]["next_page"] == 2)
            check("accessible_second_page_found", [item["rid"] for item in pages[1]["items"]] == [ids["own"]] and pages[1]["next_page"] == 3)
            check("query_exhaustion_terminates", pages[2]["items"] == [] and pages[2]["next_page"] is None)
            for ability in ("get-post-context", "get-post-markdown", "list-content-context", "get-changed-content"):
                params = {"input[post_id]": ids["own"]} if "get-post" in ability else {"input[per_page]": 2}
                if ability == "get-changed-content":
                    params["input[since]"] = "2020-01-01T00:00:00Z"
                response = request("/wp-abilities/v1/abilities/wp-context/" + ability + "/run", params, role="author")
                check("ability_" + ability + "_http_success", response[0] == 200)
                if ability == "get-post-context":
                    check("ability_cid_not_mislabelled_etag", "cid" in json.loads(response[2]) and "etag" not in json.loads(response[2]))
            check("http_reads_do_not_mutate_posts_or_postmeta", fixture("state") == initial_state)
            check("fixture_changes_only_link_dependency", fixture("link-change")["changed"])
            linked = request(post, headers={"If-None-Match": compact[1]["etag"]})
            linked_data = json.loads(linked[2])
            check("link_only_change_keeps_cid", linked_data["cid"] == data["cid"])
            check("link_only_change_returns_new_200_identity", identity(linked) and linked[1]["etag"] != compact[1]["etag"])
            check("link_only_response_difference", {k: v for k, v in data.items() if k != "links"} ==
                  {k: v for k, v in linked_data.items() if k != "links"} and data["links"] != linked_data["links"])
            check("author_edit_does_not_change_post_timestamp", fixture("author-change")["post_modified_unchanged"])
            changed = request(post, headers={"If-None-Match": linked[1]["etag"]})
            check("related_author_change_invalidates_context", identity(changed) and json.loads(changed[2])["cid"] != linked_data["cid"])
            response = request("/wp-context/v1/catalog", {"since": "2026-01-02T00:00:00Z", "status": "publish"})
            check("timestamp_discovery_is_not_dependency_change_feed", ids["published"] not in [item["rid"] for item in json.loads(response[2])["items"]])
        report["stage"] = "complete"
    except Exception as error:
        report["error_class"] = type(error).__name__
        if isinstance(error, RuntimeError) and re.fullmatch(r"[a-z_0-9-]{1,120}", str(error)):
            report["failure"] = str(error)
        report["ok"] = False
        print("Stopped: " + type(error).__name__, flush=True)
    finally:
        cleanup_ok = True
        for kind, names in (("container", (web, database)), ("volume", volumes), ("network", (network,))):
            for name in names:
                try:
                    existing = docker(kind, "ls", "--quiet", "--filter", "name=" + name, *( ["--all"] if kind == "container" else []))
                    if existing:
                        docker(kind, "rm", *( ["--force"] if kind == "container" else []), name)
                    remaining = docker(kind, "ls", "--quiet", "--filter", "name=" + name, *( ["--all"] if kind == "container" else []))
                    cleanup_ok = cleanup_ok and not remaining
                except Exception:
                    cleanup_ok = False
        unchanged = before == {name: sha((ROOT / name).read_bytes()) for name in RUNTIME}
        checks.extend(({"name": "disposable_resources_removed", "ok": cleanup_ok},
                       {"name": "runtime_source_unchanged", "ok": unchanged}))
        report["checks"] = len(checks)
        report["failed"] = sum(not item["ok"] for item in checks)
        report["ok"] = "error_class" not in report and report["failed"] == 0
        args.output.parent.mkdir(parents=True, exist_ok=True)
        with args.output.open("x", encoding="utf-8", newline="\n") as output:
            json.dump(report, output, indent=2)
            output.write("\n")
    print(json.dumps({key: report[key] for key in ("ok", "checks", "failed")}))
    return 0 if report["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
