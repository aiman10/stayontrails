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


class TestListImages:
    def test_returns_image_list_with_status(self, client):
        r = client.get("/api/models/demo/images")
        assert r.status_code == 200
        body = r.get_json()
        assert body["ok"] is True
        files = [i["file"] for i in body["images"]]
        assert files == ["frame_001.jpg", "frame_002.jpg"]
        # frame_001 had a segment in the fixture so it's "in-progress".
        statuses = {i["file"]: i["status"] for i in body["images"]}
        assert statuses["frame_001.jpg"] == "in-progress"
        # frame_002 has no annotation entry -> "unlabeled" by default.
        assert statuses["frame_002.jpg"] == "unlabeled"

    def test_unknown_model_404(self, client):
        r = client.get("/api/models/nope/images")
        assert r.status_code == 404
