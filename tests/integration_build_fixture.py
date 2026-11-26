#!/usr/bin/env python3
"""Build a private integration input from the supplied June SQL evidence.

No customer data is embedded in this script. Write its result outside the
plugin tree; never distribute the JSON or generated reports with the plugin.
Usage: python integration_build_fixture.py WORKSPACE OUTPUT_JSON
Requires openpyxl only to read the archived workbook (never rewrites it).
"""
import csv
import json
import sys
from collections import defaultdict
from datetime import datetime, timedelta
from pathlib import Path

import openpyxl

root = Path(sys.argv[1]).resolve()
out = Path(sys.argv[2]).resolve()
query_paths = json.loads((root / "analysis/root/query_files.json").read_text())


def rows(key):
    with open(query_paths[key], encoding="utf-8-sig", newline="") as stream:
        return list(csv.DictReader(stream))


def integer(value, default=0):
    return int(value) if value not in (None, "") else default


def date(value):
    if value in (None, ""):
        return None
    if isinstance(value, datetime):
        return value.isoformat(sep=" ")
    return str(value)


def duration(value):
    return int(value.total_seconds()) if isinstance(value, timedelta) else value


categories = {integer(r["id"]): r for r in rows("G03")}
groups = {integer(r["id"]): r for r in rows("G03b")}
actors = defaultdict(list)
for row in rows("G06"):
    actors[integer(row["ticket_id"])].append(row)
surveys = {}
for row in rows("G18"):
    tid = integer(row["tickets_id"])
    if tid not in surveys or integer(row["survey_id"]) > integer(surveys[tid]["survey_id"]):
        surveys[tid] = row

status_rows = [{"ticket_id": integer(r["ticket_id"]), "log_id": integer(r["log_id"]),
                "occurred_at": r["evento_em"], "actor_raw": r["autor_historico_bruto"],
                "from_status": integer(r["status_anterior_numerico"]),
                "to_status": integer(r["status_novo_numerico"])} for r in rows("G04") if r["log_id"]]
solution_rows = [{"ticket_id": integer(r["ticket_id"]), "solution_id": integer(r["solucao_id"]),
                  "solution_at": r["solucao_criada_em"], "solver_id": integer(r["autor_id"]),
                  "solver_name": r["autor_nome_gravado"], "solution_status": integer(r["solucao_status_bruto"])}
                 for r in rows("G05") if r["solucao_id"]]
member_rows = [{"user_id": integer(r["usuario_id_referenciado"]),
                "user_name": (r["usuario_primeiro_nome_atual"] + " " + r["usuario_sobrenome_atual"]).strip(),
                "group_id": integer(r["grupo_id"]), "group_name": r["grupo_nome_completo_atual"]}
               for r in rows("G07") if r["grupo_id"]]
reopened = {r["ticket_id"] for r in status_rows if r["from_status"] in (5, 6) and r["to_status"] in (1, 2, 3, 4)}
refused = {r["ticket_id"] for r in solution_rows if r["solution_status"] == 4}
links = defaultdict(list)
for row in rows("G17"):
    if row["knowbaseitems_id"]:
        links[integer(row["tickets_id"])].append(integer(row["knowbaseitems_id"]))

agreements = {}
for r in rows("G12"):
    row = {"id": integer(r["sla_id"]), "name": r["sla_nome"], "entities_id": integer(r["sla_entidade_id"]),
           "type": integer(r["sla_tipo"]), "number_time": integer(r["number_time"]),
           "definition_time": r["definition_time"], "end_of_working_day": integer(r["end_of_working_day"]),
           "use_ticket_calendar": integer(r["sla_usa_calendario_chamado"]), "calendars_id": integer(r["sla_calendario_id"]),
           "slms_id": integer(r["slms_id"]), "parent_slm_calendars_id": integer(r["slm_calendario_id"]),
           "parent_slm_use_ticket_calendar": integer(r["slm_usa_calendario_chamado"]),
           "parent_slm_mismatch": bool(integer(r["configuracao_sla_difere_slm"])),
           "date_creation": r["sla_criado_em"], "date_mod": r["sla_alterado_em"]}
    agreements[row["id"]] = row
calendars = {integer(r["calendario_id"]): {"id": integer(r["calendario_id"]), "name": r["calendario_nome"],
             "entities_id": integer(r["entities_id"]), "date_creation": r["date_creation"], "date_mod": r["date_mod"],
             "segments": [], "holidays": []} for r in rows("G13")}
for r in rows("G14"):
    calendars[integer(r["calendario_id"])]["segments"].append({"id": integer(r["segmento_id"]),
        "day": integer(r["dia_codigo"]), "begin": r["hora_inicio"], "end": r["hora_fim"]})
for r in rows("G15"):
    if r["feriado_id_encontrado"]:
        calendars[integer(r["calendario_id"])]["holidays"].append({"id": integer(r["feriado_id_encontrado"]),
            "name": r["feriado_nome"], "begin_date": r["begin_date"], "end_date": r["end_date"],
            "is_perpetual": integer(r["is_perpetual"]), "date_creation": r["date_creation"], "date_mod": r["date_mod"]})
entities = {integer(r["id"]): {"id": integer(r["id"]), "entities_id": integer(r["entidade_pai"]),
            "calendars_id": integer(r["calendars_id"]), "calendars_strategy": integer(r["calendars_strategy"])} for r in rows("G22")}
histories = defaultdict(list)
for r in rows("G09"):
    histories[integer(r["tickets_id"])].append({"id": integer(r["log_id"]), "items_id": integer(r["tickets_id"]),
        "itemtype": r["itemtype"], "itemtype_link": r["itemtype_link"], "linked_action": integer(r["linked_action"]),
        "date_mod": r["evento_em"], "id_search_option": integer(r["id_search_option"]),
        "old_value": r["old_value"], "new_value": r["new_value"], "old_id": integer(r["old_id"]),
        "new_id": integer(r["new_id"]), "is_clone": integer(r["mensagem_clone"])})

tickets = []
for r in rows("G02"):
    tid = integer(r["id"])
    category = categories.get(integer(r["itilcategories_id"]), {})
    assigned = sorted({integer(a["ator_id"]) for a in actors[tid] if a["ator_tipo"] == "GRUPO" and a["papel_codigo"] == "2" and integer(a["ator_id"]) > 0})
    def names(role):
        return "; ".join((a["usuario_primeiro_nome_atual"] + " " + a["usuario_sobrenome_atual"]).strip()
                         for a in actors[tid] if a["ator_tipo"] == "USUARIO" and a["papel_codigo"] == str(role))
    ticket = {"id": tid, "entity_id": integer(r["entities_id"]), "title": r["name"], "type": integer(r["type"]),
        "status": integer(r["status"]), "priority": integer(r["priority"]), "category_id": integer(r["itilcategories_id"]),
        "category": category.get("completename", ""), "opened_at": date(r["date"]), "created_at": date(r["date_creation"]),
        "solved_at": date(r["solvedate"]), "closed_at": date(r["closedate"]), "deadline": date(r["time_to_resolve"]),
        "sla_id": integer(r["slas_id_ttr"]), "sla_name": agreements.get(integer(r["slas_id_ttr"]), {}).get("name", ""),
        "waiting_seconds": integer(r["waiting_duration"]), "sla_waiting_seconds": integer(r["sla_waiting_duration"]),
        "solve_seconds": integer(r["solve_delay_stat"]), "elapsed_seconds": integer(r["tempo_corrido_s"]),
        "within_sla": None if not r["time_to_resolve"] else bool(integer(r["nao_excedido_prazo_nativo"])),
        "group_ids": assigned, "groups": "; ".join(groups[g]["completename"] for g in assigned),
        "n1_eligible": False, "reopened": tid in reopened, "refused_solution": tid in refused,
        "kb_category_id": integer(category.get("knowbaseitemcategories_id")), "kb_ids": links[tid], "kb_validated": False,
        "satisfaction": integer(surveys.get(tid, {}).get("satisfaction"), None), "technicians": names(2), "requesters": names(1),
        "location": "", "location_id": integer(r["locations_id"])}
    tickets.append(ticket)
tickets.sort(key=lambda t: (t["solved_at"], t["id"]), reverse=True)

# Calls are frozen from the previously supplied export; this does not rerun or
# reinterpret telephony SQL. They exercise source breakdowns and document charts.
book = openpyxl.load_workbook(root / "reference/Relatorio_Mensal_SES_2026-06 (1).xlsx", read_only=True, data_only=True)
calls = []
for values in book["Base de Dados Ligações"].iter_rows(min_row=7, values_only=True):
    if not values or not values[0]:
        continue
    r = list(values) + [None] * 35
    calls.append({"id": str(r[0]), "queue": r[2], "entered_at": r[30] or date(r[3]), "connected_at": date(r[4]),
        "completed_at": date(r[5]), "abandoned_at": date(r[6]), "caller": str(r[7]), "type": r[8], "termination": r[9],
        "agent": r[10], "disconnect": "", "talk_seconds": duration(r[11]), "wait_seconds": duration(r[12]),
        "abandon_wait_seconds": duration(r[13]), "satisfaction": r[14], "satisfaction_raw": r[24],
        "survey_id": str(r[25]) if r[25] is not None else None, "survey_at": date(r[26]),
        "repeated_entries": 1, "completion_events": int(r[5] is not None), "transfer_events": 0})
book.close()
fixture = {
    "input": {"month": "2026-06", "kb_mode": "category_proxy", "ins12_mode": "glpi_only"},
    "settings": {"ses_test_numbers_json": "[]", "ses_staff_register_json": "[]"},
    "data": {"tickets": tickets, "calls": calls, "warnings": [],
        "capabilities": {"reopening": True, "satisfaction": True, "phone_survey": True, "kb_links": True},
        "selection_catalog": {"groups": {k: {"id": k, "name": v["completename"]} for k,v in groups.items()},
            "categories": {k: {"id": k, "name": v["completename"]} for k,v in categories.items()}, "articles": [], "articles_available": True},
        "sla_context": {"timezone": "America/Sao_Paulo", "database_timezone": {"session_timezone": "-03:00", "utc_offset_seconds": -10800},
            "agreements": agreements, "calendars": calendars, "entities": entities, "histories": histories,
            "history_available": True, "capabilities": {"schema": True, "timezone": True, "agreements": True,
                "parent_slms": True, "entities": True, "calendars": True, "history": True, "history_numeric_ids": True}}},
    "actor_rows": {"status": status_rows, "solutions": solution_rows, "members": member_rows},
    "expected": {"INS1": [0,0], "INS2": [22,30], "INS3": [2,12], "INS4": [6123,6223],
        "INS5": [251,6567], "INS6": [4601,5381], "INS11": [5973,6567], "INS12": [619,739]},
    "provenance": {"tickets": "G02 population and G03–G22 raw evidence; no manual management adjustments",
        "calls": "Frozen prior plugin export: 76 post-exclusion call rows; no new call SQL requested",
        "personnel": "Empty by design: client confirmed register data explains INS10, no formula change"}}
# Read INS1/2 expected cohort counts from the independently completed analysis.
cohorts = json.loads((root / "analysis/sla/summary.json").read_text())["cohorts"]
for metric in ["INS1", "INS2"]:
    fixture["expected"][metric] = [cohorts[metric]["reconstruction_within_seconds"], cohorts[metric]["total"]]
out.parent.mkdir(parents=True, exist_ok=True)
out.write_text(json.dumps(fixture, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
print(json.dumps({"file": str(out), "tickets": len(tickets), "calls": len(calls), "history_rows": sum(map(len, histories.values())), "bytes": out.stat().st_size}))
