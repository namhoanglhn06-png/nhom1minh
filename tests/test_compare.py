from app import compare_records


def test_compare_records_detects_difference():
    version_a = [{"id": 1, "name": "Alice"}, {"id": 2, "name": "Bob"}]
    version_b = [{"id": 1, "name": "Alice"}, {"id": 2, "name": "Bobby"}, {"id": 3, "name": "Charlie"}]

    result = compare_records(version_a, version_b)

    assert result["summary"]["added"] == 1
    assert result["summary"]["removed"] == 0
    assert result["summary"]["modified"] == 1
    assert result["key_field"] == "id"


def test_compare_records_handles_removal():
    version_a = [{"id": 1, "name": "Alice"}, {"id": 2, "name": "Bob"}]
    version_b = [{"id": 1, "name": "Alice"}]

    result = compare_records(version_a, version_b)

    assert result["summary"]["added"] == 0
    assert result["summary"]["removed"] == 1
