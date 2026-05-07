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


class TestLoadAnnotations:
    def test_returns_v1_normalized_to_v2(self, client):
        r = client.get("/api/models/demo/annotations")
        assert r.status_code == 200
        body = r.get_json()
        assert body["ok"] is True
        assert body["data"]["schemaVersion"] == 2
        assert body["data"]["classes"][0]["color"] == "#7C3AED"
        assert body["data"]["images"][0]["annotations"]["boxes"] == []
        assert body["status"] == "ok"

    def test_missing_file_returns_default_scaffold(self, client, record_root):
        # Delete the annotations file to test the missing case.
        (record_root / "demo" / "annotations.json").unlink()
        r = client.get("/api/models/demo/annotations")
        assert r.status_code == 200
        body = r.get_json()
        assert body["status"] == "missing"
        assert body["data"]["model"] == "demo"
        assert len(body["data"]["classes"]) == 4  # path-oxod + grass + puddle + road


class TestReviewedRoute:
    def test_list_images_includes_reviewed_false_by_default(self, client):
        r = client.get("/api/models/demo/images")
        assert r.status_code == 200
        body = r.get_json()
        for img in body["images"]:
            assert img["reviewed"] is False

    def test_post_reviewed_marks_image(self, client, record_root):
        r = client.post(
            "/api/models/demo/images/frame_001.jpg/reviewed",
            json={"reviewed": True},
        )
        assert r.status_code == 200
        assert r.get_json() == {"ok": True, "reviewed": True}
        # Persisted to disk.
        import json as _json
        on_disk = _json.loads((record_root / "demo" / "reviewed.json").read_text())
        assert on_disk == ["frame_001.jpg"]

    def test_post_reviewed_unmarks_image(self, client, record_root):
        client.post("/api/models/demo/images/frame_001.jpg/reviewed", json={"reviewed": True})
        r = client.post(
            "/api/models/demo/images/frame_001.jpg/reviewed",
            json={"reviewed": False},
        )
        assert r.status_code == 200
        assert r.get_json() == {"ok": True, "reviewed": False}

    def test_post_reviewed_default_is_true(self, client):
        r = client.post("/api/models/demo/images/frame_001.jpg/reviewed", json={})
        assert r.status_code == 200
        assert r.get_json()["reviewed"] is True

    def test_post_reviewed_404_unknown_image(self, client):
        r = client.post(
            "/api/models/demo/images/frame_999.jpg/reviewed",
            json={"reviewed": True},
        )
        assert r.status_code == 404

    def test_post_reviewed_400_unsafe_filename(self, client):
        r = client.post(
            "/api/models/demo/images/..%2Fpasswd/reviewed",
            json={"reviewed": True},
        )
        assert r.status_code in (400, 404)  # werkzeug may 404 on bad path, that's fine

    def test_list_images_reflects_reviewed_state(self, client):
        client.post("/api/models/demo/images/frame_001.jpg/reviewed", json={"reviewed": True})
        r = client.get("/api/models/demo/images")
        body = r.get_json()
        states = {img["file"]: img["reviewed"] for img in body["images"]}
        assert states["frame_001.jpg"] is True
        assert states["frame_002.jpg"] is False


class TestSaveAnnotationsRoute:
    def test_put_writes_file_and_returns_ok(self, client, record_root):
        payload = {
            "model": "demo",
            "schemaVersion": 2,
            "classes": [{"id": 0, "name": "path", "color": "#22d3ee"}],
            "images": [],
        }
        r = client.put(
            "/api/models/demo/annotations",
            json=payload,
        )
        assert r.status_code == 200
        assert r.get_json() == {"ok": True}
        # Round-trip: load it back via the GET route.
        r2 = client.get("/api/models/demo/annotations")
        assert r2.get_json()["data"]["classes"] == payload["classes"]

    def test_put_rejects_non_json(self, client):
        r = client.put("/api/models/demo/annotations", data="not json", content_type="text/plain")
        assert r.status_code == 400

    def test_put_rejects_wrong_shape(self, client):
        r = client.put("/api/models/demo/annotations", json={"model": "demo"})  # missing classes/images
        assert r.status_code == 400

    def test_put_unknown_model_404(self, client):
        r = client.put("/api/models/nope/annotations", json={"model": "nope", "classes": [], "images": []})
        assert r.status_code == 404


class TestImageRoute:
    def test_serves_existing_image(self, client):
        r = client.get("/img/demo/frame_001.jpg")
        assert r.status_code == 200
        # 4-byte placeholder JPEG from the fixture.
        assert r.data == b"\xff\xd8\xff\xd9"

    def test_unknown_image_404(self, client):
        r = client.get("/img/demo/missing.jpg")
        assert r.status_code == 404

    def test_traversal_filename_400(self, client):
        r = client.get("/img/demo/..%2Fapp.py")
        assert r.status_code in (400, 404)

    def test_unsafe_extension_400(self, client):
        r = client.get("/img/demo/foo.txt")
        assert r.status_code == 400
