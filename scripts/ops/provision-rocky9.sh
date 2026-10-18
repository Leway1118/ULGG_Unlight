#!/usr/bin/env bash
set -Eeuo pipefail

# ULGG Rocky Linux 9 bootstrap provisioning
#
# Stage 1 only:
# - prepare a fresh Rocky Linux 9 VPS
# - install the production-compatible runtime stack
# - create the leway admin account
# - add SSH 22222 while KEEPING port 22 during validation
# - create 2 GiB swap
# - leave DNS / TLS / application data untouched
#
# Required:
#   run as root on the NEW VPS
#   export LEWAY_SSH_PUBLIC_KEY='ssh-ed25519 AAAA... comment'
#
# Optional:
#   export ULGG_SWAP_GB=2

log() {
    printf '\n[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

die() {
    echo "[ABORT] $*" >&2
    exit 1
}

[[ "${EUID}" -eq 0 ]] || die "run this script as root"

# Strong guard against accidentally running bootstrap on the current production VPS.
if [[ -f /var/www/html/unlight/.env ]] \
   || systemctl is-active --quiet unlight_watcher.service 2>/dev/null; then
    die "existing ULGG production detected; this script is for a fresh VPS only"
fi

[[ -r /etc/os-release ]] || die "/etc/os-release not found"
# shellcheck disable=SC1091
source /etc/os-release

[[ "${ID:-}" == "rocky" ]] || die "Rocky Linux required; detected ID=${ID:-unknown}"
[[ "${VERSION_ID:-}" == 9* ]] || die "Rocky Linux 9 required; detected VERSION_ID=${VERSION_ID:-unknown}"

LEWAY_SSH_PUBLIC_KEY="${LEWAY_SSH_PUBLIC_KEY:-}"
[[ -n "${LEWAY_SSH_PUBLIC_KEY}" ]] || die "set LEWAY_SSH_PUBLIC_KEY before running"

case "${LEWAY_SSH_PUBLIC_KEY}" in
    ssh-ed25519\ *|ssh-rsa\ *|ecdsa-sha2-nistp256\ *|ecdsa-sha2-nistp384\ *|ecdsa-sha2-nistp521\ *)
        ;;
    *)
        die "LEWAY_SSH_PUBLIC_KEY does not look like an OpenSSH public key"
        ;;
esac

ULGG_SWAP_GB="${ULGG_SWAP_GB:-2}"
[[ "${ULGG_SWAP_GB}" =~ ^[1-9][0-9]*$ ]] || die "ULGG_SWAP_GB must be a positive integer"

log "System identity"
cat /etc/os-release
uname -r
echo "hostname=$(hostname -f 2>/dev/null || hostname)"

log "Set timezone and time synchronization"
timedatectl set-timezone Asia/Taipei

log "Update base system"
dnf -y upgrade --refresh

log "Enable EPEL"
dnf -y install epel-release

log "Install ULGG runtime packages"
dnf -y install \
    nginx \
    mariadb mariadb-server mariadb-backup \
    php php-cli php-common php-fpm php-gd php-mbstring \
    php-mysqlnd php-opcache php-pdo php-xml \
    python3.11 python3.11-pip python3.11-devel \
    git rsync curl wget unzip tar jq \
    gcc gcc-c++ make openssl-devel libffi-devel \
    firewalld policycoreutils-python-utils \
    chrony cronie \
    certbot python3-certbot-nginx \
    ca-certificates openssl sudo

log "Install Composer 2 with installer signature verification"
EXPECTED_SIG="$(curl -fsSL https://composer.github.io/installer.sig)"
php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
ACTUAL_SIG="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"

[[ "${EXPECTED_SIG}" == "${ACTUAL_SIG}" ]] \
    || die "Composer installer signature mismatch"

php /tmp/composer-setup.php \
    --quiet \
    --install-dir=/usr/local/bin \
    --filename=composer
rm -f /tmp/composer-setup.php

log "Create admin user: leway"
if ! id leway >/dev/null 2>&1; then
    useradd --create-home --shell /bin/bash leway
fi

usermod -aG wheel leway

install -d -m 0700 -o leway -g leway /home/leway/.ssh

AUTHORIZED_KEYS=/home/leway/.ssh/authorized_keys
touch "${AUTHORIZED_KEYS}"
chown leway:leway "${AUTHORIZED_KEYS}"
chmod 0600 "${AUTHORIZED_KEYS}"

if ! grep -Fqx "${LEWAY_SSH_PUBLIC_KEY}" "${AUTHORIZED_KEYS}"; then
    printf '%s\n' "${LEWAY_SSH_PUBLIC_KEY}" >> "${AUTHORIZED_KEYS}"
fi

log "Configure SSH bootstrap ports: keep 22 and add 22222"
install -d -m 0755 /etc/ssh/sshd_config.d

cat > /etc/ssh/sshd_config.d/20-ulgg-bootstrap.conf <<'EOF'
# ULGG migration bootstrap
# Port 22 remains open until port 22222 is verified from the client.
Port 22
Port 22222
PubkeyAuthentication yes
EOF

# Prepare SELinux policy for future Enforcing mode.
if semanage port -l | awk '$1 == "ssh_port_t" {print $0}' | grep -qw 22222; then
    :
else
    semanage port -a -t ssh_port_t -p tcp 22222 2>/dev/null \
        || semanage port -m -t ssh_port_t -p tcp 22222
fi

/usr/sbin/sshd -t

log "Set SELinux to permissive during migration"
if command -v getenforce >/dev/null 2>&1; then
    if [[ "$(getenforce)" != "Disabled" ]]; then
        setenforce 0 || true
    fi
fi

if [[ -f /etc/selinux/config ]]; then
    sed -ri 's/^SELINUX=.*/SELINUX=permissive/' /etc/selinux/config
fi

log "Configure 2-port bootstrap firewall"
systemctl enable --now firewalld

firewall-cmd --permanent --add-service=ssh
firewall-cmd --permanent --add-port=22222/tcp
firewall-cmd --reload

# Deliberately DO NOT expose HTTP/HTTPS yet.
# Nginx will be tested locally until the application and Cloudflare-only
# firewall rules are prepared.

log "Create swap if the VPS has none"
if ! swapon --show=NAME --noheadings | grep -q .; then
    SWAPFILE=/swapfile

    if [[ ! -f "${SWAPFILE}" ]]; then
        fallocate -l "${ULGG_SWAP_GB}G" "${SWAPFILE}" \
            || dd if=/dev/zero of="${SWAPFILE}" bs=1M count="$((ULGG_SWAP_GB * 1024))" status=progress
    fi

    chmod 0600 "${SWAPFILE}"

    if ! file "${SWAPFILE}" | grep -q 'swap file'; then
        mkswap "${SWAPFILE}"
    fi

    swapon "${SWAPFILE}"

    if ! grep -qE '^[[:space:]]*/swapfile[[:space:]]' /etc/fstab; then
        echo '/swapfile none swap sw 0 0' >> /etc/fstab
    fi
fi

cat > /etc/sysctl.d/90-ulgg-swap.conf <<'EOF'
vm.swappiness = 10
EOF
sysctl --system >/dev/null

log "Enable base services"
systemctl enable --now chronyd
systemctl enable --now crond
systemctl enable --now mariadb
systemctl enable --now php-fpm
systemctl enable --now nginx

log "Reload SSH after validation"
systemctl reload sshd

log "Local service smoke tests"
curl -fsSI http://127.0.0.1/ >/dev/null \
    || die "local nginx HTTP smoke test failed"

mariadb-admin ping --silent >/dev/null \
    || die "MariaDB ping failed"

php -r 'echo "php-ok\n";' >/dev/null
python3.11 -c 'import sys; print(sys.version)' >/dev/null
composer --version >/dev/null

log "Provisioning summary"
echo "Rocky:      ${PRETTY_NAME}"
echo "Timezone:   $(timedatectl show -p Timezone --value)"
echo "SELinux:    $(getenforce 2>/dev/null || echo unknown)"
echo "CPU:        $(nproc)"
free -h
df -h /
swapon --show

echo
echo "Versions:"
nginx -v 2>&1
php -v | head -1
mariadb --version
python3.11 --version
composer --version
certbot --version

echo
echo "SSH listeners:"
ss -lnt | awk 'NR == 1 || $4 ~ /:(22|22222)$/'

echo
echo "Firewall:"
firewall-cmd --list-all

echo
echo "Enabled services:"
for svc in sshd firewalld chronyd crond mariadb php-fpm nginx; do
    printf '%-12s enabled=%-8s active=%s\n' \
        "${svc}" \
        "$(systemctl is-enabled "${svc}" 2>/dev/null || true)" \
        "$(systemctl is-active "${svc}" 2>/dev/null || true)"
done

cat <<'EOF'

============================================================
ULGG ROCKY 9 STAGE 1 COMPLETE
============================================================

DO NOT close the current root session yet.

Next mandatory test from your own computer:
  ssh -p 22222 leway@NEW_VPS_IP

Only after that login succeeds should port 22 be removed.

Not performed in Stage 1:
  - UL.GG source/data copy
  - MariaDB database restore
  - production .env copy
  - Nginx ulgg.online vhost
  - Let's Encrypt certificate issuance
  - Cloudflare-only HTTP/HTTPS firewall
  - DNS cutover
  - production systemd units / cron jobs

============================================================
EOF
