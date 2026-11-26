#!/usr/bin/env python3
"""Check generated Office ZIP/XML structure and agreement with the frozen snapshot.

This is a package/data check, not a substitute for opening in Microsoft Office.
Usage: python integration_check_office.py PRIVATE_OUTPUT_DIRECTORY
"""
import json
import posixpath
import sys
import zipfile
from pathlib import Path

from lxml import etree
import openpyxl

out = Path(sys.argv[1])
snapshot = json.loads((out / "snapshot.json").read_text())
metrics = {m["id"]: m for m in snapshot["metrics"]}
failures, checks = [], []
ns = {"c": "http://schemas.openxmlformats.org/drawingml/2006/chart",
      "s": "http://schemas.openxmlformats.org/spreadsheetml/2006/main",
      "a": "http://schemas.openxmlformats.org/drawingml/2006/main",
      "p": "http://schemas.openxmlformats.org/package/2006/relationships"}


def check(condition, label, detail=None):
    (checks if condition else failures).append({"check": label, "detail": detail})


for filename in ("report.docx", "report.xlsx"):
    with zipfile.ZipFile(out / filename) as archive:
        check(archive.testzip() is None, filename + ": CRC")
        names = archive.namelist()
        check(len(names) == len(set(names)), filename + ": unique ZIP part names")
        documents = {}
        xml_count = 0
        for name in names:
            if not name.endswith((".xml", ".rels")):
                continue
            try:
                document = etree.fromstring(archive.read(name))
                xml_count += 1
                if name.endswith('.rels') or '/charts/' in name:
                    documents[name] = document
                del document
            except etree.XMLSyntaxError as error:
                check(False, filename + ": well-formed XML " + name, str(error))
        check(xml_count > 0, filename + ": XML parsed", xml_count)
        missing = []
        for name, document in documents.items():
            if not name.endswith(".rels"):
                continue
            base = "" if name == "_rels/.rels" else posixpath.dirname(posixpath.dirname(name))
            ids = [r.get("Id") for r in document]
            check(len(ids) == len(set(ids)), filename + ": relationship IDs " + name)
            for relation in document:
                if relation.get("TargetMode") == "External":
                    continue
                target = relation.get("Target", "")
                resolved = posixpath.normpath(posixpath.join(base, target)) if not target.startswith("/") else target[1:]
                if resolved not in names:
                    missing.append({"source": name, "target": resolved})
        check(not missing, filename + ": all internal relationship targets exist", missing)
        charts = {n: d for n, d in documents.items() if "/charts/" in n and n.endswith(".xml")}
        check(bool(charts), filename + ": native charts present", len(charts))
        for name, doc in charts.items():
            is_circular = bool(doc.xpath("//c:pieChart | //c:doughnutChart", namespaces=ns))
            if is_circular:
                check(bool(doc.xpath("//c:legend", namespaces=ns)), filename + ": pie/doughnut legend " + name)
            check(not doc.xpath("//c:lineChart", namespaces=ns), filename + ": no requested-replaced line charts " + name)
            for cache in doc.xpath("//c:numCache | //c:strCache | //c:numLit | //c:strLit", namespaces=ns):
                count = cache.find("c:ptCount", ns)
                if count is not None:
                    # Missing numeric points represent blanks (e.g. an unavailable
                    # INS10), not zero. Sparse caches retain the category count.
                    indices = [int(pt.get('idx')) for pt in cache.findall('c:pt', ns)]
                    check(len(indices) == len(set(indices)) and all(0 <= i < int(count.get('val')) for i in indices),
                          filename + ": chart cache indices " + name)
            colors = doc.xpath("//a:srgbClr/@val", namespaces=ns)
            check(all(len(v) == 6 and all(c in "0123456789abcdefABCDEF" for c in v) for v in colors), filename + ": RGB colors " + name)

book = openpyxl.load_workbook(out / "report.xlsx", read_only=True, data_only=True)
with zipfile.ZipFile(out / 'report.xlsx') as archive:
    tables = {}
    for name in archive.namelist():
        if name.startswith('xl/tables/') and name.endswith('.xml'):
            table = etree.fromstring(archive.read(name))
            tables[table.get('name')] = table.get('ref')
    # Exporter sheet order is explicit: consolidated first, then INS1..INS12.
    metric_links = {}
    for index in range(1, 13):
        document = etree.fromstring(archive.read('xl/worksheets/sheet' + str(index + 1) + '.xml'))
        metric_links['INS' + str(index)] = document.xpath('//s:hyperlink/@location', namespaces=ns)
for metric, m in metrics.items():
    for cell, field in [("B8", "numerator"), ("B9", "denominator")]:
        if m[field] is not None:
            check(book[metric][cell].value == m[field], metric + ": cached " + field,
                  {"cell": book[metric][cell].value, "snapshot": m[field]})
    if m["value"] is not None:
        check(abs(book[metric]["B10"].value * 100 - m["value"]) < 1e-8, metric + ": cached result")
for metric in ["INS7", "INS8", "INS9"]:
    table = tables.get("Evidencias_" + metric)
    check(table is not None, metric + ": counted-call detail table exists")
    if table is not None:
        _, first, _, last = openpyxl.utils.range_boundaries(table)
        check(last - first == metrics[metric]["numerator"], metric + ": initial detailed calls match numerator",
              {"rows": last - first, "metric": metrics[metric]["numerator"]})
    links = metric_links[metric]
    check(any("Base de Dados Ligações" in x for x in links), metric + ": full denominator remains accessible", links)

# All ticket tables are the selected metric populations, not copies of all tickets.
for metric in ["INS1", "INS2", "INS3", "INS4", "INS5", "INS6", "INS11", "INS12"]:
    table = tables.get("Evidencias_" + metric)
    if table is None:
        check(metrics[metric]["denominator"] == 0, metric + ": zero-population table policy")
        continue
    _, first, _, last = openpyxl.utils.range_boundaries(table)
    expected = len(metrics[metric].get("denominator_ids", []))
    check(last - first == expected, metric + ": detail rows match denominator", {"rows": last-first, "denominator": expected})
book.close()
result = {"passed": len(checks), "failures": failures,
          "limits": "Checks ZIP, XML relationships, caches, legends and detail-table membership. Native Microsoft Office rendering remains a separate manual check."}
(out / "office_checks.json").write_text(json.dumps(result, ensure_ascii=False, indent=2))
print(json.dumps(result, ensure_ascii=False, indent=2))
sys.exit(bool(failures))
