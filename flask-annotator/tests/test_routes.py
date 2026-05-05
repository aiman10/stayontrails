class TestModelPicker:
    def test_root_without_model_renders_picker(self, client):
        r = client.get("/")
        assert r.status_code == 200
        assert b"demo" in r.data  # model slug appears in the picker page

    def test_root_with_model_renders_index(self, client):
        r = client.get("/?model=demo")
        assert r.status_code == 200
        # The annotation page injects the model slug as a JS constant.
        assert b'"demo"' in r.data

    def test_root_with_unknown_model_404(self, client):
        r = client.get("/?model=does-not-exist")
        assert r.status_code == 404

    def test_root_with_traversal_model_400(self, client):
        # Slugify strips ../, so "../etc" -> "etc". That folder doesn't exist
        # -> 404. There is no way to traverse out of RECORD_ROOT.
        r = client.get("/?model=../etc")
        assert r.status_code in (400, 404)
