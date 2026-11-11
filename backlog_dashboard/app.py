from __future__ import annotations

import os
import re
from pathlib import Path
from datetime import datetime, timezone
import hashlib
from flask import Flask, jsonify, render_template

CHECKBOX = re.compile(r"^\s*- \[([ xX~])\]\s+(.*)$")
HEADING = re.compile(r"^(#{1,6})\s+(.+?)\s*#*\s*$")
LINK = re.compile(r"\[([^\]]+)\]\(([^)]+)\)")


def _clean(value: str) -> str:
    value = LINK.sub(r"\1", value)
    value = re.sub(r"`([^`]*)`", r"\1", value)
    value = re.sub(r"\*\*(.*?)\*\*|\*(.*?)\*", lambda m: m.group(1) or m.group(2), value)
    return value.strip()


def load_tasks(docs_dir: Path) -> dict:
    tasks = []
    sources = sorted(docs_dir.glob("*.md"))
    for source in sources:
        text = source.read_text(encoding="utf-8")
        headings: list[str] = []
        for number, line in enumerate(text.splitlines(), 1):
            heading = HEADING.match(line)
            if heading:
                level = len(heading.group(1))
                headings = headings[:level - 1]
                headings.append(_clean(heading.group(2)))
                continue
            checkbox = CHECKBOX.match(line)
            if not checkbox:
                continue
            mark, description = checkbox.groups()
            status = {"x": "done", "X": "done", "~": "in_progress", " ": "todo"}[mark]
            progress = {"done": 100, "in_progress": 50, "todo": 0}[status]
            tasks.append({
                "id": f"{source.stem}:{number}",
                "file": source.name,
                "path": source.name,
                "line": number,
                "section": headings[-1] if headings else source.stem,
                "heading_path": headings.copy(),
                "text": _clean(description),
                "status": status,
                "progress_percent": progress,
            })
    counts = {status: sum(item["status"] == status for item in tasks) for status in ("todo", "in_progress", "done")}
    counts["total"] = len(tasks)
    file_progress = []
    for source in sources:
        file_tasks = [item for item in tasks if item["file"] == source.name]
        completed = sum(item["status"] == "done" for item in file_tasks)
        weighted = sum(item["progress_percent"] for item in file_tasks)
        file_progress.append({"file": source.name, "total": len(file_tasks), "done": completed, "percent": round(weighted / len(file_tasks)) if file_tasks else 0})
    counts["completion_percent"] = round(counts["done"] * 100 / len(tasks)) if tasks else 0
    signature = "|".join(f"{p.name}:{p.stat().st_mtime_ns}:{p.stat().st_size}" for p in sources)
    return {"tasks": tasks, "summary": counts, "files": [p.name for p in sources], "file_progress": file_progress, "updated_at": datetime.now(timezone.utc).isoformat(), "signature": signature}


def create_app(docs_dir: str | Path | None = None) -> Flask:
    app = Flask(__name__)
    source = Path(docs_dir or os.environ.get("NONOGRAM_BACKLOG_DIR", Path(__file__).resolve().parent.parent / "DOCS")).resolve()
    app.config["TEMPLATES_AUTO_RELOAD"] = True
    app_root = Path(__file__).resolve().parent

    def app_signature() -> str:
        files = [Path(__file__).resolve()]
        for folder in (app_root / "templates", app_root / "static"):
            if folder.is_dir():
                files.extend(path for path in folder.rglob("*") if path.is_file())
        digest = hashlib.sha256()
        for path in sorted(files):
            digest.update(path.relative_to(app_root).as_posix().encode())
            digest.update(path.read_bytes())
        return digest.hexdigest()

    @app.get("/")
    def index():
        if not source.is_dir():
            return f"Backlog directory not found: {source}", 404
        return render_template("index.html", docs_path=str(source))

    @app.get("/api/dev-reload")
    def dev_reload():
        return jsonify({"signature": app_signature()})

    @app.get("/api/tasks")
    def tasks():
        if not source.is_dir():
            return jsonify({"error": f"Backlog directory not found: {source}"}), 404
        return jsonify(load_tasks(source))

    return app


if __name__ == "__main__":
    create_app().run(host="127.0.0.1", port=int(os.environ.get("PORT", 5055)), debug=True, use_reloader=True)
