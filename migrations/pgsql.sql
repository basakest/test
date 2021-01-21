CREATE TABLE IF NOT EXISTS %table_name% (
  id bigserial NOT NULL,
  ptype varchar(255) NOT NULL,
  v0 varchar(255) DEFAULT NULL,
  v1 varchar(255) DEFAULT NULL,
  v2 varchar(255) DEFAULT NULL,
  v3 varchar(255) DEFAULT NULL,
  v4 varchar(255) DEFAULT NULL,
  v5 varchar(255) DEFAULT NULL,
  PRIMARY KEY (id)
);