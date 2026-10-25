"""Fail closed if runtime secrets are downloadable. Never log response bodies."""
import urllib.error
import urllib.request


def verify(base="https://www.sv-netzwerk.eu"):
    for path in ("/.env", "/%2eenv", "/.env?probe=security", "/.env/",
                 "/.env.bak", "/.env~", "/.git/config", "/.git/HEAD",
                 "/intern/api/schema.sql"):
        try:
            with urllib.request.urlopen(base + path, timeout=15) as response:
                status = response.status
        except urllib.error.HTTPError as error:
            status = error.code
        if status not in (403, 404):
            raise SystemExit(f"SECURITY BOUNDARY FAILED: {path}: HTTP {status}")
        print(f"Protected: {path}: HTTP {status}")


if __name__ == "__main__":
    verify()
