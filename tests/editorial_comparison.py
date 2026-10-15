"""Small synthetic retrieval comparison; no model invocation or model scoring."""
import hashlib
import html
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import statistics
import time


TASK = """Review this unpublished draft using only the supplied context.
Report its title, publication status, author, section heading, checklist items
and nesting, inline image description and caption, internal link destination,
category, tag, and featured image description. Flag missing information instead
of inventing it. Then give a brief editorial assessment with evidence.
"""


class Document(HTMLParser):
    def __init__(self, source):
        super().__init__(convert_charrefs=True)
        self.root = {"tag": "document", "attrs": {}, "children": []}
        self.stack = [self.root]
        self.feed(source)

    def handle_starttag(self, tag, attrs):
        node = {"tag": tag, "attrs": dict(attrs), "children": []}
        self.stack[-1]["children"].append(node)
        if tag not in ("img", "br", "hr", "input", "meta", "link", "source", "wbr", "area", "embed"):
            self.stack.append(node)

    def handle_startendtag(self, tag, attrs):
        self.handle_starttag(tag, attrs)
        if self.stack[-1]["tag"] == tag:
            self.stack.pop()

    def handle_endtag(self, tag):
        for index in range(len(self.stack) - 1, 0, -1):
            if self.stack[index]["tag"] == tag:
                del self.stack[index:]
                break

    def handle_data(self, data):
        self.stack[-1]["children"].append(data)


def nodes(node, tag):
    result = []
    for child in node["children"]:
        if isinstance(child, dict):
            if child["tag"] == tag:
                result.append(child)
            result.extend(nodes(child, tag))
    return result


def text(node):
    return "".join(child if isinstance(child, str) else text(child) for child in node["children"]).strip()


def core_facts(data):
    document = Document(data["content"]["rendered"]).root
    embedded = data["_embedded"]
    terms = [term for group in embedded.get("wp:term", []) for term in group]
    unordered = [node for node in nodes(document, "ul") if not nodes(node, "ol")]
    nested = [node for node in nodes(document, "li") if nodes(node, "ol")]
    images, captions, links = nodes(document, "img"), nodes(document, "figcaption"), nodes(document, "a")
    return {
        "title": data["title"]["raw"], "status": data["status"],
        "author": embedded.get("author", [{}])[0].get("name"),
        "heading": next((text(node) for node in nodes(document, "h2")), None),
        "paragraph": next((text(node).split(" Read the ")[0] for node in nodes(document, "p")), None),
        "unordered_items": [text(node) for node in nodes(unordered[0], "li")] if unordered else [],
        "nested_list": (["".join(c for c in nested[0]["children"] if isinstance(c, str)).strip()]
                        + [text(node) for node in nodes(nested[0], "li")]) if nested else [],
        "inline_image_alt": images[0]["attrs"].get("alt") if images else None,
        "image_caption": text(captions[0]) if captions else None,
        "internal_link": links[0]["attrs"].get("href") if links else None,
        "category": next((term["name"] for term in terms if term.get("taxonomy") == "category"), None),
        "tag": next((term["name"] for term in terms if term.get("taxonomy") == "post_tag"), None),
        "featured_image_alt": embedded.get("wp:featuredmedia", [{}])[0].get("alt_text"),
    }


def mr_facts(data):
    blocks = data["blocks"]
    headings = [block for block in blocks if block["type"] == "core/heading"]
    paragraphs = [block for block in blocks if block["type"] == "core/paragraph"]
    lists = [block for block in blocks if block["type"] == "core/list"]
    images = [block for block in blocks if block["type"] == "core/image"]
    flat = [block for block in lists if not any("\n" in item for item in block["items"])]
    nested = [block for block in lists if any("\n" in item for item in block["items"])]
    return {
        "title": data["title"], "status": data["status"], "author": data["author"]["name"],
        "heading": html.unescape(headings[0]["content"]) if headings else None,
        "paragraph": html.unescape(paragraphs[0]["content"]).split(" Read the ")[0] if paragraphs else None,
        "unordered_items": flat[0]["items"] if flat else [],
        "nested_list": [re.sub(r"^\s*\d+\.\s*", "", line) for line in nested[0]["items"][0].splitlines()] if nested else [],
        "inline_image_alt": images[0].get("altText") if images else None,
        "image_caption": images[0].get("caption") if images else None,
        "internal_link": next((target.get("url") for block in blocks
                               for target in block.get("link_targets", []) if target.get("url")), None),
        "category": data["categories"][0]["name"] if data["categories"] else None,
        "tag": data["tags"][0]["name"] if data["tags"] else None,
        "featured_image_alt": (data.get("image") or {}).get("alt"),
    }


def compare(request, fixture, check, ids, directory):
    expected = fixture("editorial-setup")["expected"]
    initial_state = fixture("state")
    core_route = "/wp/v2/posts/" + str(ids["own"])
    mr_route = "/wp-context/v1/posts/" + str(ids["own"])
    core_params = {
        "context": "edit", "_embed": "author,wp:term,wp:featuredmedia",
        "_fields": "id,status,title.raw,content.rendered,author,categories,tags,featured_media,_links,_embedded",
    }
    variants = {"core_rest_embedded": [(core_route, core_params)], "context_mr": [(mr_route, {})],
                "context_mr_and_markdown": [(mr_route, {}), (mr_route + "/md", {})]}
    samples = {name: [] for name in variants}
    responses = {}
    # One warm-up per variant, then five rounds with reversed ordering on alternate rounds.
    for round_index in range(6):
        order = list(variants) if round_index % 2 == 0 else list(reversed(variants))
        for name in order:
            started = time.perf_counter()
            received = [request(route, params, role="author") for route, params in variants[name]]
            elapsed = (time.perf_counter() - started) * 1000
            if any(response[0] != 200 for response in received):
                raise RuntimeError("editorial_http_status_failed")
            responses[name] = received
            if round_index:
                samples[name].append(elapsed)
    check("all_editorial_reads_succeeded", True)
    core = json.loads(responses["core_rest_embedded"][0][2])
    mr = json.loads(responses["context_mr"][0][2])
    markdown = responses["context_mr_and_markdown"][1][2].decode()
    extracted = {"core_rest_embedded": core_facts(core), "context_mr": mr_facts(mr),
                 "context_mr_and_markdown": mr_facts(json.loads(responses["context_mr_and_markdown"][0][2]))}
    # Credit Markdown with any missing literal evidence instead of assuming it adds nothing.
    combined = extracted["context_mr_and_markdown"]
    for field in ("inline_image_alt", "image_caption", "internal_link"):
        if isinstance(expected[field], str) and expected[field] in markdown:
            combined[field] = expected[field]
    check("baseline_contains_every_expected_fact", extracted["core_rest_embedded"] == expected)
    check("context_json_contains_every_expected_fact", extracted["context_mr"] == expected)
    check("context_json_and_markdown_contain_every_expected_fact", combined == expected)
    for field in ("inline_image_alt", "image_caption", "internal_link"):
        check("markdown_preserves_" + field, expected[field] in markdown)
    for name in ("context_mr", "context_mr_and_markdown"):
        for response in responses[name]:
            digest = hashlib.sha256(response[2]).hexdigest()
            check(name + "_response_identity_" + str(responses[name].index(response)),
                  response[1].get("etag") == '"sha256-' + digest + '"')
    artifacts = {
        "review-task.md": TASK.encode(),
        "core-response.json": responses["core_rest_embedded"][0][2],
        "mr-response.json": responses["context_mr"][0][2],
        "markdown-response.md": responses["context_mr_and_markdown"][1][2],
        "expected-facts.json": json.dumps(expected, indent=2).encode(),
    }
    result = {
        "task": TASK.strip(), "fixture": "One synthetic draft with ordinary core blocks and WordPress metadata.",
        "baseline": {"route": "/wp/v2/posts/{draft}", "parameters": core_params,
                     "reference": "https://developer.wordpress.org/rest-api/using-the-rest-api/global-parameters/"},
        "method": {"timed_rounds": 5, "warm_up_rounds": 1, "alternating_order": True,
                   "body_bytes_only": True, "compression": "identity", "model_calls": 0,
                   "timing_scope": "Sequential HTTP requests including authentication; excludes consumer parsing.",
                   "read_requests": 6 * sum(len(routes) for routes in variants.values()) + sum(len(routes) for routes in variants.values())},
        "variants": {},
        "limitations": ["One synthetic fixture, one local installation, small timing sample.",
                        "Fact availability is measured; no model or editorial-quality evaluation was run.",
                        "No token count, hosted latency, core/content-query, proxy, or complete block-fidelity claim.",
                        "Smaller bodies with missing facts are not equivalent context.",
                        "The compact Core consumer is fixture-focused, not a general WordPress converter."],
    }
    for name, received in responses.items():
        facts = extracted[name]
        matches = {field: facts[field] == value for field, value in expected.items()}
        compact = json.dumps(facts, ensure_ascii=False, separators=(",", ":")).encode()
        artifacts[name + "-consumer-facts.json"] = compact
        unchanged = []
        for (route, params), cached in zip(variants[name], received):
            headers = {}
            if "etag" in cached[1]:
                headers["If-None-Match"] = cached[1]["etag"]
            elif "last-modified" in cached[1]:
                headers["If-Modified-Since"] = cached[1]["last-modified"]
            response = request(route, params, role="author", headers=headers)
            if response[0] not in (200, 304):
                raise RuntimeError("editorial_revalidation_failed")
            unchanged.append({"validator_sent": bool(headers), "status": response[0], "body_bytes": len(response[2])})
        result["variants"][name] = {
            "requests_per_collection": len(received), "response_body_bytes": sum(len(item[2]) for item in received),
            "consumer_facts_bytes": len(compact), "facts_available": sum(matches.values()), "facts_total": len(matches),
            "fact_matches": matches, "missing_facts": [field for field, ok in matches.items() if not ok],
            "latency_ms_samples": [round(value, 3) for value in samples[name]],
            "latency_ms_median": round(statistics.median(samples[name]), 3),
            "unchanged_follow_up": unchanged,
        }
    check("editorial_gets_preserve_posts_and_postmeta", fixture("state") == initial_state)
    output = Path(directory) / "editorial"
    output.mkdir(parents=True, exist_ok=False)
    result["artifacts"] = {}
    for name, body in artifacts.items():
        with (output / name).open("xb") as artifact:
            artifact.write(body)
        result["artifacts"][name] = {"bytes": len(body), "sha256": hashlib.sha256(body).hexdigest()}
    return result
