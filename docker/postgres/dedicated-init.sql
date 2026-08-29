-- ═══════════════════════════════════════════════════════════════
-- Cluster DEDICADO — una base por tenant que salio del compartido.
--
-- Mismos dos roles y mismo esquema que el compartido. Que el
-- esquema sea identico (incluida la columna tenant_id y las
-- politicas de RLS, aunque aca haya un solo tenant) es deliberado:
-- promover un tenant es mover datos, nunca transformar estructura.
-- Ese es el motivo por el que `tenant:promote` puede ser un comando
-- y no un proyecto de migracion.
-- ═══════════════════════════════════════════════════════════════

CREATE ROLE d4s_owner LOGIN PASSWORD 'owner_secret';
CREATE ROLE d4s_app   LOGIN PASSWORD 'app_secret';

ALTER ROLE d4s_owner NOBYPASSRLS NOCREATEROLE NOSUPERUSER;
ALTER ROLE d4s_app   NOBYPASSRLS NOCREATEROLE NOSUPERUSER NOCREATEDB;

CREATE DATABASE d4s_tenant_mega OWNER d4s_owner;

\connect d4s_tenant_mega

REVOKE ALL ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO d4s_owner;
GRANT USAGE ON SCHEMA public TO d4s_app;

ALTER DEFAULT PRIVILEGES FOR ROLE d4s_owner IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO d4s_app;
ALTER DEFAULT PRIVILEGES FOR ROLE d4s_owner IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO d4s_app;
