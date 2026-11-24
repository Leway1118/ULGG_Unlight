#!/usr/bin/env bash
set -Eeuo pipefail
test "$RUNNER_OS" = Linux
test "$#" = 2
version="$1"; evidence="$2"
case "$version" in 8.0|8.3) ;; *) exit 2;; esac
mkdir -p "$evidence"
stage="$(mktemp -d "$RUNNER_TEMP/m3c-stage-XXXXXX")"
net=m3c-internal
cleanup(){
  status=$?
  trap - EXIT
  set +e
  docker rm -fv m3c-app m3c-db m3c-client m3c-writer m3c-bootstrap >/dev/null 2>&1
  docker network rm "$net" >/dev/null 2>&1
  rm -rf "$stage"
  failed=0
  if docker ps -a --format '{{.Names}}' | grep -Eq '^m3c-(app|db|client|writer|bootstrap)$'; then failed=1; fi
  if docker network inspect "$net" >/dev/null 2>&1; then failed=1; fi
  if test -e "$stage"; then failed=1; fi
  if test "$failed" = 0; then
    printf '{"containers_removed":true,"internal_network_removed":true,"ledger_and_state_removed":true,"production_connected":false}\n' > "$evidence/cleanup.json"
    echo M3C_CLEANUP_PASS
  else
    echo M3C_CLEANUP_FAIL; status=1
  fi
  exit "$status"
}
trap cleanup EXIT
# Only dependency acquisition uses the hosted runner's external network.
printf 'FROM php:%s-cli\nRUN docker-php-ext-install pdo_mysql\n' "$version" | docker build -t m3c-php - 
printf 'FROM mcr.microsoft.com/playwright/python:v1.56.0-noble\nRUN pip install --no-cache-dir playwright==1.56.0\n' | docker build -t m3c-browser -
docker pull mariadb:11.4
docker image inspect m3c-php m3c-browser mariadb:11.4 --format '{{.Id}} {{json .RepoDigests}}' > "$evidence/images.txt"
docker network create --internal --subnet 172.29.77.0/24 "$net"
test "$(docker network inspect "$net" --format '{{.Internal}}')" = true
docker run -d --rm --name m3c-db --network "$net" --tmpfs /var/lib/mysql:rw,nosuid,nodev,size=256m \
  -e MARIADB_ROOT_PASSWORD=synthetic-m3c-root-only -e MARIADB_DATABASE=ulgg_m3c_synthetic mariadb:11.4
ready=0
for i in $(seq 1 90); do
  if docker exec m3c-db healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then ready=1; break; fi
  sleep 1
done
test "$ready" = 1
# Mount only the checkpoint subset; never mount .git, environment, repository config or other files.
mounts=(--mount "type=bind,src=$GITHUB_WORKSPACE/lib/checkpoint2026,dst=/repo/lib/checkpoint2026,readonly" --mount "type=bind,src=$GITHUB_WORKSPACE/tests/checkpoint2026/FixtureData.php,dst=/repo/tests/checkpoint2026/FixtureData.php,readonly")
for f in common.php bootstrap.php build.php router.php; do
  test -f "$GITHUB_WORKSPACE/tests/checkpoint2026/m3c/$f"
done
base=(--network "$net" --read-only --cap-drop ALL --security-opt no-new-privileges --user "$(id -u):$(id -g)" --tmpfs /tmp:rw,nosuid,nodev,size=64m --env M3C_STAGE=SYNTHETIC_INTERNAL_ONLY)
bootstrap(){
  docker run --rm --name m3c-bootstrap "${base[@]}" "${mounts[@]}" \
    --mount "type=bind,src=$GITHUB_WORKSPACE/tests/checkpoint2026/m3c/common.php,dst=/repo/tests/checkpoint2026/m3c/common.php,readonly" \
    --mount "type=bind,src=$GITHUB_WORKSPACE/tests/checkpoint2026/m3c/bootstrap.php,dst=/repo/tests/checkpoint2026/m3c/bootstrap.php,readonly" \
    m3c-php php /repo/tests/checkpoint2026/m3c/bootstrap.php "$1"
}
writer(){
  docker run --rm --name m3c-writer "${base[@]}" "${mounts[@]}" \
    --mount "type=bind,src=$GITHUB_WORKSPACE/tests/checkpoint2026/m3c/common.php,dst=/repo/tests/checkpoint2026/m3c/common.php,readonly" \
    --mount "type=bind,src=$GITHUB_WORKSPACE/tests/checkpoint2026/m3c/build.php,dst=/repo/tests/checkpoint2026/m3c/build.php,readonly" \
    --mount "type=bind,src=$stage,dst=/stage" m3c-php php /repo/tests/checkpoint2026/m3c/build.php "$1"
}
app(){
  flag="$1"
  docker run -d --rm --name m3c-app "${base[@]}" "${mounts[@]}" \
    --env "M3C_STAGE=$flag" \
    --mount "type=bind,src=$GITHUB_WORKSPACE/tests/checkpoint2026/m3c/common.php,dst=/repo/tests/checkpoint2026/m3c/common.php,readonly" \
    --mount "type=bind,src=$GITHUB_WORKSPACE/tests/checkpoint2026/m3c/router.php,dst=/repo/tests/checkpoint2026/m3c/router.php,readonly" \
    --mount "type=bind,src=$GITHUB_WORKSPACE/pages/checkpoint2026,dst=/repo/pages/checkpoint2026,readonly" \
    --mount "type=bind,src=$stage,dst=/stage,readonly" m3c-php php -d session.save_path=/tmp -d display_errors=0 -S 0.0.0.0:8080 -t /tmp /repo/tests/checkpoint2026/m3c/router.php
  test "$(docker inspect m3c-app --format '{{json .HostConfig.PortBindings}}')" = '{}'
  test "$(docker inspect m3c-app --format '{{.HostConfig.ReadonlyRootfs}}')" = true
  docker exec m3c-app php -r 'if(is_writable("/stage"))exit(2);'
}
client(){
  docker run --rm --name m3c-client --network "$net" --shm-size=256m --security-opt no-new-privileges \
    --mount "type=bind,src=$GITHUB_WORKSPACE/tests/checkpoint2026/m3c/browser.py,dst=/browser.py,readonly" \
    --mount "type=bind,src=$evidence,dst=/evidence" m3c-browser python /browser.py "$1"
}
bootstrap seed
writer privileges
writer v1
app DISABLED
client disabled
docker rm -f m3c-app
app SYNTHETIC_INTERNAL_ONLY
client v1
bootstrap revise
writer v2
client v2
writer rollback
client rollback
writer forged
client forged
writer history-conflict
client history-conflict
bootstrap missing
writer missing
client missing
cp "$stage/"*.json "$evidence/"
python3 - "$evidence" <<'PY'
import pathlib,json,sys
p=pathlib.Path(sys.argv[1])
def read(n):return json.loads((p/(n+".json")).read_text())
v1,v2=read("v1"),read("v2")
for scope in ("A","B","PUBLIC"):
    assert v2["scopes"][scope]["previous"]==v1["scopes"][scope]["hash"]
assert [v1["scopes"]["A"]["pvp"],v2["scopes"]["A"]["pvp"]]==[3,4]
assert [v1["scopes"]["PUBLIC"]["cup"],v2["scopes"]["PUBLIC"]["cup"]]==[2,3]
assert read("rollback")["records"]==6 and read("rollback")["denied_writes"]==2
assert read("privileges")["denials"]==8
for mode in ("forged","history-conflict","missing"):
    assert read(mode)["source_status"]=="UNKNOWN" and read(mode)["records_unchanged"]
for mode in ("disabled","v1","v2","rollback","forged","history-conflict","missing"):
    assert read(mode+"-http")["status"]=="PASS"
assert len(list(p.glob("*.png")))==12
print("M3C_INTEGRATED_ALL_GATES_PASS")
PY
