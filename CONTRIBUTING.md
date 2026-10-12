# Contributing

## Testing

```bash
vendor/bin/pest                               # everything, on SQLite
vendor/bin/pest --exclude-group=integration   # skip the slower integration tests
```

The integration tests (`tests/Integration`) run without a wrapping transaction and start `vendor/bin/testbench`
processes. On SQLite they share the `build/integration.sqlite` file.

To run the suite against MySQL, MariaDB or Postgres, select the connection with environment variables:

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=rm_q_test DB_USERNAME=root DB_PASSWORD=secret \
    vendor/bin/pest
```

Add `RM_Q_TEST_DB_TIMEZONE=+05:00` to skew the database session timezone, as CI does.

## Static analysis

```bash
vendor/bin/phpstan
```

## Formatting

```bash
vendor/bin/pint
```
