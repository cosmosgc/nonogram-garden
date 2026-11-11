import tempfile
import unittest
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from app import create_app, load_tasks


class BacklogDashboardTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.docs = Path(self.temp.name)
        (self.docs / '01-slice.md').write_text('''# Backlog
## Domain rules
- [x] **Grid contract** — tested
- [~] Add clue generator
- [ ] Add draft API
''', encoding='utf-8')
        self.client = create_app(self.docs).test_client()

    def tearDown(self):
        self.temp.cleanup()

    def test_parses_status_headings_and_counts(self):
        result = load_tasks(self.docs)
        self.assertEqual([task['status'] for task in result['tasks']], ['done', 'in_progress', 'todo'])
        self.assertEqual(result['summary']['total'], 3)
        self.assertEqual(result['summary']['completion_percent'], 33)
        self.assertEqual(result['tasks'][1]['section'], 'Domain rules')

    def test_index_and_json_endpoint(self):
        self.assertEqual(self.client.get('/').status_code, 200)
        response = self.client.get('/api/tasks')
        self.assertEqual(response.status_code, 200)
        self.assertEqual(len(response.json['tasks']), 3)

    def test_dev_reload_endpoint_returns_app_signature(self):
        response = self.client.get('/api/dev-reload')
        self.assertEqual(response.status_code, 200)
        self.assertEqual(len(response.json['signature']), 64)
        self.assertIn(b'watchApp', self.client.get('/').data)

    def test_source_changes_are_visible_on_next_request(self):
        path = self.docs / '01-slice.md'
        path.write_text(path.read_text(encoding='utf-8').replace('- [ ] Add draft API', '- [x] Add draft API'), encoding='utf-8')
        self.assertEqual(self.client.get('/api/tasks').json['summary']['done'], 2)


if __name__ == '__main__':
    unittest.main()
