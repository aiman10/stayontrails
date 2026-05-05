import pytest

from slug import slugify, is_safe_filename


class TestSlugify:
    def test_lowercases(self):
        assert slugify("My Trail") == "my-trail"

    def test_strips_punctuation(self):
        assert slugify("My Trail!") == "my-trail"

    def test_collapses_whitespace_and_underscores(self):
        assert slugify("foo   bar__baz") == "foo-bar-baz"

    def test_rejects_path_traversal(self):
        assert slugify("../etc/passwd") == "etc-passwd"

    def test_strips_leading_trailing_hyphens(self):
        assert slugify("---path---") == "path"

    def test_empty(self):
        assert slugify("") == ""

    def test_unicode_letters_dropped(self):
        # We only keep ASCII alphanumerics + hyphens. é -> "-" -> stripped.
        assert slugify("café") == "caf"


class TestIsSafeFilename:
    def test_accepts_jpg(self):
        assert is_safe_filename("frame_001.jpg") is True

    def test_accepts_png(self):
        assert is_safe_filename("img.png") is True

    def test_accepts_jpeg(self):
        assert is_safe_filename("img.jpeg") is True

    def test_rejects_traversal(self):
        assert is_safe_filename("../foo.jpg") is False

    def test_rejects_backslash(self):
        assert is_safe_filename("foo\\bar.jpg") is False

    def test_rejects_no_extension(self):
        assert is_safe_filename("frame") is False

    def test_rejects_other_extensions(self):
        assert is_safe_filename("frame.gif") is False
        assert is_safe_filename("frame.txt") is False

    def test_case_insensitive_extension(self):
        assert is_safe_filename("frame.JPG") is True
