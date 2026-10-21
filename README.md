# SYD — Switch Backup Store

**English** · [Türkçe](README.tr.md)

A panel that shows the configuration backups left by Juniper switches. Same
template, same design and same identity center as the sibling panels (KYM, EYS,
NYS, SYS).

    Data      switch backup directory    (mounted READ-ONLY into the container as /yedekler)
    Sign-in   only through the Identity Management Center

## ⚠ CRITICAL

The backup directory is often **the only copy** of the device configurations and
the sole record left in case of a failure. Therefore:

- The panel **has no write endpoint.** No delete, move or upload; a test locks
  this in (`YedekEkraniTest::test_yazma_ucu_yoktur`).
- The directory is mounted into the container with `:ro`: even if the code ever
  tried to write by mistake, the file system would not allow it.
- Devices drop their backups through a path outside the panel (e.g. FTP). The
  panel does not touch it; if it breaks, the backup flow stops silently and is
  noticed weeks later.
- Before touching anything on the server, a backup is taken and the backup is
  copied **to a second machine**.

## Access and audit

- Sign-in goes through **the identity center**; the center decides who may enter
  (the role list on the Clients screen).
- The account opens by itself once the center allows it; the panel additionally
  has a `perm_yedek` permission that can be changed from the administrator screen.
- **Every download lands in the audit log** (`yedek_indirildi`).

## The date is read from the file name

Devices drop files in the form `...conf.gz_20181006_160347`. When a file is
copied somewhere, `mtime` shifts to the moment of copying and a 2018 backup looks
like "today". Sorting and display use the stamp in the name; without a stamp it
falls back to `mtime` and the row is marked "ad damgasız" (no name stamp).

## Certificate

In the standard Compose installation TLS is terminated by the reverse proxy in
front of the panel, so the certificate is managed there and Settings → Certificate
shows "unknown" (see `docker/README.md` → Certificate tab).

An installation that runs the certificate agent (`sertifika-ajan.sh`) mounts the
panel's request and status files into the container and manages the round from
Settings → Certificate, as in the sibling panels:

1. **Start a new round** → the panel leaves a request file, the agent runs
   (`sertifika-ajan.sh`, triggered when the request file appears) and writes the
   TXT record to add on screen.
2. You add the record to DNS by hand; the screen checks by itself whether it has
   propagated.
3. **Confirm** → the agent obtains the certificate and installs it into the
   container.

The PANEL DOES NOT obtain the certificate: the ACME account and the private key
stay on the server, the panel only leaves a request. So that a compromised panel
cannot issue certificates for our address.

## Operation

Installation uses `docker-compose.yml` at the repository root (Compose standard;
the same file on every platform). Details and variables: `docker/README.md`,
`.env.example`.

    cp .env.example .env && docker compose up -d --build   # install / update
    docker compose logs -f panel                           # logs
    docker compose exec panel supervisorctl status         # services
