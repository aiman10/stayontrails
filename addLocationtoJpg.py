from pathlib import Path
from fractions import Fraction
import pandas as pd
from PIL import Image
import piexif


def to_deg(value: float):
    """Convert decimal degrees to EXIF DMS rational format."""
    abs_value = abs(value)
    degrees = int(abs_value)
    minutes_float = (abs_value - degrees) * 60
    minutes = int(minutes_float)
    seconds = (minutes_float - minutes) * 60

    def rational(x: float, max_denominator: int = 1000000):
        f = Fraction(x).limit_denominator(max_denominator)
        return (f.numerator, f.denominator)

    return (
        rational(degrees),
        rational(minutes),
        rational(seconds),
    )


def gps_ifd(latitude: float, longitude: float, altitude: float | None = None):
    lat_ref = "N" if latitude >= 0 else "S"
    lon_ref = "E" if longitude >= 0 else "W"

    gps = {
        piexif.GPSIFD.GPSLatitudeRef: lat_ref.encode(),
        piexif.GPSIFD.GPSLatitude: to_deg(latitude),
        piexif.GPSIFD.GPSLongitudeRef: lon_ref.encode(),
        piexif.GPSIFD.GPSLongitude: to_deg(longitude),
    }

    if altitude is not None:
        gps[piexif.GPSIFD.GPSAltitudeRef] = 0 if altitude >= 0 else 1
        alt = Fraction(abs(altitude)).limit_denominator(1000)
        gps[piexif.GPSIFD.GPSAltitude] = (alt.numerator, alt.denominator)

    return gps


def write_gps_to_image(image_path: Path, latitude: float, longitude: float, altitude: float | None = None):
    image = Image.open(image_path)

    try:
        exif_dict = piexif.load(image.info.get("exif", b""))
    except Exception:
        exif_dict = {"0th": {}, "Exif": {}, "GPS": {}, "1st": {}, "thumbnail": None}

    exif_dict["GPS"] = gps_ifd(latitude, longitude, altitude)
    exif_bytes = piexif.dump(exif_dict)

    image.save(image_path, exif=exif_bytes)


def main():
    image_folder = Path("img\\laerbeekbos")
    csv_path = Path("img\\laerbeekbos\\inference_log.csv")

    df = pd.read_csv(csv_path)

    for _, row in df.iterrows():
        filename = str(row["Filename"])
        latitude = float(row["latitude"])
        longitude = float(row["longitude"])
        altitude = 0.0

        image_path = image_folder / filename

        if not image_path.exists():
            print(f"Skipping, file not found: {image_path}")
            continue

        try:
            print(f"Processing: {image_path} with lat={latitude}, lon={longitude}")
            write_gps_to_image(image_path, latitude, longitude, altitude)
            print(f"Updated: {image_path}")
        except Exception as e:
            print(f"Failed for {image_path}: {e}")


if __name__ == "__main__":
    main()