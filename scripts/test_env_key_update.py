import unittest
from env_key_update import replace_key


class EnvUpdateTests(unittest.TestCase):
    def test_binary_database_secret_is_preserved(self):
        # Includes the separator that caused the production incident, invalid
        # UTF-8, and every other non-LF control character.
        database = b'DB_PASS=' + bytes(c for c in range(256) if c != 10) + b'\n'
        original = database + b'OPENAI_ASSISTANT_API_KEY=old\nAPP_SECRET=unchanged\r\n'
        self.assertEqual(replace_key(original, b'OPENAI_ASSISTANT_API_KEY', b'new'),
                         database + b'APP_SECRET=unchanged\r\nOPENAI_ASSISTANT_API_KEY=new\n')

    def test_duplicate_target_keys_only_are_removed(self):
        self.assertEqual(replace_key(b'A=1\nK=old\nK=older\nZ=2', b'K', b'new'),
                         b'A=1\nZ=2\nK=new\n')

    def test_no_newline_injection(self):
        with self.assertRaises(ValueError):
            replace_key(b'A=1\n', b'K', b'x\nDB_PASS=bad')


if __name__ == '__main__':
    unittest.main()
