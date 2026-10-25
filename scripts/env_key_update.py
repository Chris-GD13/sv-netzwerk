"""Update one dotenv key without decoding or normalizing unrelated bytes."""


def replace_key(raw: bytes, name: bytes, value: bytes) -> bytes:
    if not name or any(c in name for c in (b'=', b'\n', b'\r')):
        raise ValueError('Invalid environment key')
    if b'\n' in value or b'\r' in value:
        raise ValueError('Invalid environment value')
    prefix = name + b'='
    # splitlines() also splits control characters that can belong to secrets.
    pieces = raw.split(b'\n')
    kept = [line + (b'\n' if i < len(pieces) - 1 else b'')
            for i, line in enumerate(pieces) if not line.startswith(prefix)]
    result = b''.join(kept)
    if result and not result.endswith(b'\n'):
        result += b'\n'
    return result + prefix + value + b'\n'
