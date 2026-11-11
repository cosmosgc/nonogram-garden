# Nonogram backlog dashboard

A local, read-only Flask app for monitoring Markdown task checkboxes in `../DOCS`. The Markdown files remain authoritative; the dashboard never changes task state. It polls every four seconds and reflects edits without a manual reload.

## Run on Windows

From the project root:

```powershell
py -m pip install -r backlog_dashboard/requirements.txt
py backlog_dashboard/app.py
```

Open <http://127.0.0.1:5055>. The app binds to loopback only. In this local development mode, Flask's reloader restarts the server when Python code changes, and the page reloads automatically when the app code, templates, or static assets change. Markdown backlog edits continue to update in place without a page reload. To use another Markdown backlog directory, set `NONOGRAM_BACKLOG_DIR` before launch.

Checkbox mapping: `[ ]` to do, `[~]` in progress, `[x]` done. Tasks are grouped by source Markdown file and their most specific preceding heading. The dashboard shows totals, completion percentage, search, and status filtering.

## Tests

```powershell
py -m unittest discover -s backlog_dashboard/tests -v
```
