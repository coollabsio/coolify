psql -U postgres -c "create table if not exists test (id SERIAL UNIQUE NOT NULL,article TEXT);"
psql -U postgres -c "insert into test (article) select md5(random()::text) from generate_series(1, 1000000) s(i);"
