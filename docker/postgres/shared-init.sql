-- ═══════════════════════════════════════════════════════════════
-- Cluster COMPARTIDO — plano de control + plano de datos shared.
--
-- Dos roles con poderes distintos. Esta separacion es la que hace
-- que Row-Level Security sea una garantia real y no decorativa:
--
--   d4s_owner : dueno del esquema. Corre migraciones. Puede todo.
--               Solo lo usa el contenedor de inicializacion.
--   d4s_app   : runtime de la aplicacion. NO es dueno de ninguna
--               tabla, por lo tanto NO puede saltarse las politicas
--               de RLS. Es el unico rol que ve el contenedor de la
--               app y el de los workers.
--
-- En Postgres el dueno de una tabla se saltea RLS por defecto. Por
-- eso el rol de la app no puede ser dueno, y ademas cada tabla se
-- marca con FORCE ROW LEVEL SECURITY en la migracion. Dos candados
-- sobre el mismo punto.
-- ═══════════════════════════════════════════════════════════════

CREATE ROLE d4s_owner LOGIN PASSWORD 'owner_secret';
CREATE ROLE d4s_app   LOGIN PASSWORD 'app_secret';

-- Ninguno de los dos puede saltarse RLS ni crear roles nuevos.
ALTER ROLE d4s_owner NOBYPASSRLS NOCREATEROLE NOSUPERUSER;
ALTER ROLE d4s_app   NOBYPASSRLS NOCREATEROLE NOSUPERUSER NOCREATEDB;

CREATE DATABASE d4s_system OWNER d4s_owner;
CREATE DATABASE d4s_shared OWNER d4s_owner;

-- Bases de la suite de tests. Separadas de las de desarrollo para que
-- correr los tests nunca pueda borrar el dataset que alguien esta mirando.
CREATE DATABASE d4s_test_system OWNER d4s_owner;
CREATE DATABASE d4s_test_shared OWNER d4s_owner;

-- ── Plano de control ──────────────────────────────────────────
\connect d4s_system

REVOKE ALL ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO d4s_owner;
GRANT USAGE ON SCHEMA public TO d4s_app;

-- La app opera sobre datos, nunca sobre estructura: no tiene CREATE.
ALTER DEFAULT PRIVILEGES FOR ROLE d4s_owner IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO d4s_app;
ALTER DEFAULT PRIVILEGES FOR ROLE d4s_owner IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO d4s_app;

-- ── Plano de datos compartido ─────────────────────────────────
\connect d4s_shared

REVOKE ALL ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO d4s_owner;
GRANT USAGE ON SCHEMA public TO d4s_app;

ALTER DEFAULT PRIVILEGES FOR ROLE d4s_owner IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO d4s_app;
ALTER DEFAULT PRIVILEGES FOR ROLE d4s_owner IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO d4s_app;

-- ── Bases de test ─────────────────────────────────────────────
\connect d4s_test_system

REVOKE ALL ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO d4s_owner;
GRANT USAGE ON SCHEMA public TO d4s_app;

ALTER DEFAULT PRIVILEGES FOR ROLE d4s_owner IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO d4s_app;
ALTER DEFAULT PRIVILEGES FOR ROLE d4s_owner IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO d4s_app;

\connect d4s_test_shared

REVOKE ALL ON SCHEMA public FROM PUBLIC;
ALTER SCHEMA public OWNER TO d4s_owner;
GRANT USAGE ON SCHEMA public TO d4s_app;

ALTER DEFAULT PRIVILEGES FOR ROLE d4s_owner IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO d4s_app;
ALTER DEFAULT PRIVILEGES FOR ROLE d4s_owner IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO d4s_app;
