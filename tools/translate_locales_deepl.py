#!/usr/bin/env python
# -*- coding: utf-8 -*-
from __future__ import print_function, unicode_literals
"""
DeepL-powered translator for Server Dashboard localization *.inc files.

Usage examples:
  # Dry-run: show what would change for German and Spanish
  python tools/translate_locales_deepl.py --locales de_DE,es_ES --dry-run

  # Translate all locales that have a mapping and write files
  DEEPL_API_KEY=your_key python tools/translate_locales_deepl.py --all

  # Use the free endpoint (api-free.deepl.com) and increase formality
  DEEPL_API_KEY=your_key python tools/translate_locales_deepl.py --locales fr_FR --free --formality more

Notes
- Only strings that are empty or currently equal to English will be translated.
- Placeholders like %s, %d, {name}, :token, URLs, etc. are protected from translation.
- Output preserves single-quote PHP array syntax with correct escaping.
- A JSON report is emitted by default to tools/translate_report.json
"""
import argparse
import os
import re
import sys
import json
import time
import io


try:
    import requests
except Exception as e:
    requests = None

# Lightweight fallback if 'requests' isn't available.
# Uses urllib to POST application/x-www-form-urlencoded with multiple 'text' fields.
def _post_form(url, fields, headers, timeout):
    if requests is not None:
        # Use requests directly
        resp = requests.post(url, data=fields, headers=headers, timeout=timeout)
        return resp.status_code, resp.text
    # urllib fallback
    try:
        # Python 3
        import urllib.request as _u
        import urllib.parse as _p
    except Exception:
        # Python 2
        import urllib2 as _u
        import urllib as _p  # type: ignore
    data = _p.urlencode(fields, doseq=True).encode("utf-8")
    req = _u.Request(url, data=data)
    for k, v in (headers or {}).items():
        req.add_header(k, v)
    try:
        res = _u.urlopen(req, timeout=timeout)
        code = getattr(res, "status", getattr(res, "code", 200))
        text = res.read().decode("utf-8")
        return code, text
    except Exception as e:
        # Try to surface useful error
        try:
            code = e.code  # type: ignore
            text = e.read().decode("utf-8")  # type: ignore
            return code, text
        except Exception:
            raise


HERE = os.path.abspath(os.path.dirname(__file__))
ROOT = os.path.abspath(os.path.join(HERE, os.pardir))
LOC_DIR = os.path.join(ROOT, "localization")
REPORT_PATH = os.path.join(ROOT, "tools", "translate_report.json")

DEEPL_API = "https://api.deepl.com/v2/translate"
DEEPL_API_FREE = "https://api-free.deepl.com/v2/translate"

BATCH_SIZE = 25  # DeepL allows up to 50 texts per call; keep some margin

# Map our locale filenames to DeepL language codes
DEEPL_CODE_MAP = {
    "de_DE": "DE",
    "es_ES": "ES",
    "fr_FR": "FR",
    "it_IT": "IT",
    "pt_BR": "PT-BR",
    "pt_PT": "PT-PT",
    "nl_NL": "NL",
    "sv_SE": "SV",
    "da_DK": "DA",
    "nb_NO": "NB",
    "fi_FI": "FI",
    "pl_PL": "PL",
    "cs_CZ": "CS",
    "sk_SK": "SK",
    "sl_SI": "SL",
    "ro_RO": "RO",
    "hu_HU": "HU",
    "bg_BG": "BG",
    "el_GR": "EL",
    "et_EE": "ET",
    "lv_LV": "LV",
    "lt_LT": "LT",
    "tr_TR": "TR",
    "uk_UA": "UK",
    "ru_RU": "RU",
    "ja_JP": "JA",
    "zh_CN": "ZH",
    "ko_KR": "KO",
}

def die(msg, code=2):
    print("[fatal] %s" % msg, file=sys.stderr)
    sys.exit(code)

def _read(path):
    with io.open(path, 'r', encoding='utf-8') as f:
        return f.read()

def _write(path, text):
    d = os.path.dirname(path)
    if not os.path.isdir(d):
        os.makedirs(d)
    with io.open(path, 'w', encoding='utf-8') as f:
        f.write(text)

def load_locale(path):
    txt = _read(path)
    txt = re.sub(r"/\*.*?\*/", "", txt, flags=re.S)
    m = re.search(r"return\s+array\s*\((.*)\)\s*;\s*$", txt, flags=re.S)
    if not m:
        return {}
    body = m.group(1)
    pairs = re.findall(r"'([^']+)'\s*=>\s*'([^']*)'\s*,?", body)
    out = {}
    for k, v in pairs:
        out[k] = v
    return out

def php_escape(s):
    return s.replace("\\", "\\\\").replace("'", "\\'")

def write_locale(path, mapping):
    keys = sorted(mapping.keys())
    lines = ["'%s' => '%s'," % (k, php_escape(mapping[k])) for k in keys]
    out = "<?php\n\nreturn array(\n" + "\n".join(lines) + "\n);\n"
    _write(path, out)

PLACEHOLDER_RE = re.compile(
    r"""(
        %\d*\$?[sd]            |  # printf tokens like %s, %1$s, %d
        \{\{[^}]+\}\}          |  # {{double-curly}}
        \{[A-Za-z0-9_]+\}      |  # {name}
        :[A-Za-z0-9_]+         |  # :token
        \$\{?[A-Za-z0-9_]+\}?  |  # $var or ${var}
        https?://\S+           |  # URLs
        [A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,} |  # emails
        [A-Z0-9_]{3,}             # CONSTANT_LIKE
    )""",
    re.X,
)

def protect_placeholders(text):
    def repl(m):
        val = m.group(0)
        return "<nt>%s</nt>" % val
    return PLACEHOLDER_RE.sub(repl, text)

def chunked(lst, n):
    cur = []
    for x in lst:
        cur.append(x)
        if len(cur) >= n:
            yield cur
            cur = []
    if cur:
        yield cur

def deepl_translate(texts, target, auth_key, free, formality):
    url = DEEPL_API_FREE if free else DEEPL_API
    params = {
        "target_lang": target,
        "tag_handling": "xml",
        "ignore_tags": "nt",
        "preserve_formatting": "1",
        "split_sentences": "nonewlines",
    }
    if formality in ("default", "more", "less", "prefer_more", "prefer_less"):
        params["formality"] = formality
    data = []
    for t in texts:
        data.append(("text", t))
    for k in params:
        data.append((k, str(params[k])))
    headers = {"Authorization": "DeepL-Auth-Key %s" % auth_key}
    code, text = _post_form(url, data, headers, 45)
    if code == 456:
        die("Quota exceeded (456). Check your plan or reduce volume.", 3)
    if code >= 400:
        die("DeepL error %s: %s" % (code, text), 4)
    try:
        info = json.loads(text)
    except Exception as e:
        die("Failed to parse DeepL response (HTTP %s): %s" % (code, text[:400]))
    return [item.get("text", "") for item in info.get("translations", [])]

def main():
    ap = argparse.ArgumentParser()
    ag = ap.add_mutually_exclusive_group(required=True)
    ag.add_argument("--locales", help="Comma-separated list like de_DE,es_ES")
    ag.add_argument("--all", action="store_true", help="Process all mapped locales")
    ap.add_argument("--free", action="store_true", help="Use api-free.deepl.com endpoint")
    ap.add_argument("--dry-run", action="store_true", help="Do not write files")
    ap.add_argument("--formality", default="default",
                    choices=["default","more","less","prefer_more","prefer_less"],
                    help="Formality level where supported")
    ap.add_argument("--source", default="en_US.inc", help="Source english file name")
    ap.add_argument("--report", default=REPORT_PATH, help="Path to JSON report")
    args = ap.parse_args()

    auth_key = os.environ.get("DEEPL_API_KEY")
    if not auth_key and not args.dry_run:
        die("Set DEEPL_API_KEY in your environment. Use --dry-run to preview without it.")

    src_path = os.path.join(LOC_DIR, args.source)
    if not os.path.exists(src_path):
        die("Source file not found: %s" % src_path)

    en = load_locale(src_path)
    if not en:
        die("Failed to parse source file: %s" % src_path)

    if args.all:
        targets = [loc for loc in DEEPL_CODE_MAP if os.path.exists(os.path.join(LOC_DIR, loc + ".inc"))]
    else:
        targets = [s.strip() for s in args.locales.split(",") if s.strip()]

    report = {"source": args.source, "processed": []}

    for loc in targets:
        code = DEEPL_CODE_MAP.get(loc)
        loc_path = os.path.join(LOC_DIR, loc + ".inc")
        if not code:
            print("[skip] %s: no DeepL code mapping" % loc)
            continue
        if not os.path.exists(loc_path):
            print("[skip] %s: file not found at %s" % (loc, loc_path))
            continue

        cur = load_locale(loc_path)
        out = dict(cur)

        todo_keys = [k for k in en if (k not in cur) or (cur[k].strip() == "") or (cur[k] == en[k])]

        print("[%s] %d strings to translate" % (loc, len(todo_keys)))
        if not todo_keys:
            report["processed"].append({"locale": loc, "deepl_code": code, "translated_count": 0, "skipped_existing": len(en), "file": os.path.relpath(loc_path, ROOT)})
            continue

        protected_texts = [protect_placeholders(en[k]) for k in todo_keys]

        translated_texts = []
        if not args.dry_run:
            for batch in chunked(protected_texts, BATCH_SIZE):
                tr = deepl_translate(batch, code, auth_key, args.free, args.formality)
                translated_texts.extend(tr)
                time.sleep(0.4)
        else:
            translated_texts = ["[%s AUTO] %s" % (loc, t) for t in protected_texts]

        def unprotect(s):
            return s.replace("<nt>", "").replace("</nt>", "")

        changed = []
        for k, tr in zip(todo_keys, translated_texts):
            val = unprotect(tr)
            if cur.get(k) != val:
                changed.append(k)
            out[k] = val

        if not args.dry_run and changed:
            write_locale(loc_path, out)

        report["processed"].append({
            "locale": loc,
            "deepl_code": code,
            "translated_count": len(changed),
            "skipped_existing": len(en) - len(todo_keys),
            "file": os.path.relpath(loc_path, ROOT),
        })

    # write report
    rep_dir = os.path.dirname(REPORT_PATH)
    if not os.path.isdir(rep_dir):
        os.makedirs(rep_dir)
    with io.open(REPORT_PATH, "w", encoding="utf-8") as f:
        f.write(json.dumps(report, ensure_ascii=False, indent=2))

    print("[done] Report -> %s" % REPORT_PATH)
    if args.dry_run:
        print("[dry-run] No files were modified.")

if __name__ == "__main__":
    main()
