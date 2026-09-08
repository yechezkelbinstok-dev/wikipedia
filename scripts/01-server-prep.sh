#!/usr/bin/env bash
# One-time server preparation: swap + Docker.
set -euo pipefail

echo "==> Swap"
if [ "$(swapon --show --noheadings | wc -l)" -eq 0 ]; then
	sudo fallocate -l 2G /swapfile
	sudo chmod 600 /swapfile
	sudo mkswap /swapfile
	sudo swapon /swapfile
	echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab >/dev/null
	# A 4 GB box parsing Lua wants swap available but rarely used.
	echo 'vm.swappiness=10' | sudo tee /etc/sysctl.d/99-swappiness.conf >/dev/null
	sudo sysctl -q -w vm.swappiness=10
	echo "    created 2G swapfile"
else
	echo "    swap already present, skipping"
fi

echo "==> Docker"
if ! command -v docker >/dev/null 2>&1; then
	curl -fsSL https://get.docker.com | sudo sh
	sudo usermod -aG docker "$USER"
	echo "    docker installed"
else
	echo "    docker already installed, skipping"
fi

echo
echo "Done. Log out and back in (exit, then ssh again) so the docker group applies."
