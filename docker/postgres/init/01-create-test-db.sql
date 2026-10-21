-- Dedicated database for the automated test suite. Tests must never use the
-- development database (cms_sync). Runs once, as the POSTGRES_USER, on first
-- container start, so the application user owns it.
CREATE DATABASE cms_sync_test;
