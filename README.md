# check_pfsense

Nagios/Icinga plugin for pfSense, run locally on the firewall through NRPE.

A single executable (`check_pfsense.php`) with one subcommand per check. It reads the pfSense status with the same PHP functions and the same logic of the WebGUI pages and widgets, so a check reports what you would see in the GUI.

The plugin is **read-only**. It never writes the configuration, restarts services, reloads the filter or removes notices and crash reports. Where a pfSense function has side effects, the plugin reproduces only its read part (see [Read-only design](#read-only-design)).

Author: Mattia Martinello <mattia@mattiamartinello.com>

## Features

| Subcommand | Checks |
|---|---|
| [`gateways`](#gateways) | Gateway status from dpinger (delay, loss) |
| [`gateway-groups`](#gateway-groups) | Gateway groups: active tier, members down, failover |
| [`services`](#services) | pfSense services, discovered automatically |
| [`openvpn`](#openvpn) | OpenVPN servers and clients |
| [`ipsec`](#ipsec) | IPsec tunnels, phase 1 and phase 2 |
| [`wireguard`](#wireguard) | WireGuard tunnels and peers (package) |
| [`dns`](#dns) | Name resolution through the local resolver |
| [`carp`](#carp) | CARP VIPs, demotion, maintenance mode |
| [`mbuf`](#mbuf) | mbuf clusters usage |
| [`states`](#states) | pf state table usage |
| [`updates`](#updates) | System and package updates |
| [`interfaces`](#interfaces) | Link status of the assigned interfaces |
| [`certificates`](#certificates) | Certificate Manager expirations |
| [`ntp`](#ntp) | NTP synchronization |
| [`notices`](#notices) | System notices (the bell in the GUI) |
| [`crash`](#crash) | Crash reports and PHP errors |
| [`temperature`](#temperature) | CPU temperature |
| [`zfs`](#zfs) | ZFS pool health and capacity |
| [`memory`](#memory) | Memory usage |
| [`haproxy`](#haproxy) | HAProxy backends and servers (package) |
| [`speedtest`](#speedtest) | Result of a speedtest cron job |
| `list` | List the subcommands |

## Requirements

- pfSense CE 2.8.x or 2.9.x. The plugin was developed on the 2.9 and 2.7.2 sources and its logic is verified against them. 2.7.x is supported where the functions are the same. The plugin needs PHP 8.2 or later, as shipped by pfSense 2.7 and later.
- The **NRPE** package (`pfSense-pkg-nrpe`).
- The **sudo** package (`pfSense-pkg-sudo`): most checks need root privileges.
- Nothing else. The plugin uses only the pfSense PHP libraries and FreeBSD base commands (`pfctl`, `netstat`, `sysctl`, `ifconfig`, `drill`, `ntpq`, `zpool`, `pkg-static`, `wg`).

## Installation

1. Copy the plugin to the firewall and make it executable:

   ```sh
   scp check_pfsense.php admin@firewall:/tmp/
   ssh admin@firewall
   # on the firewall, as root:
   install -o root -g wheel -m 755 /tmp/check_pfsense.php /usr/local/libexec/nagios/check_pfsense.php
   ```

   The NRPE package GUI lists the `check_*` executables of `/usr/local/libexec/nagios`, so the plugin shows up in the *Command* list.

2. Install the **sudo** package (System > Package Manager) and allow the `nagios` user to run the plugin as root without a password. In **System > sudo**, add a user privilege:

   | Field | Value |
   |---|---|
   | User/Group | `nagios` |
   | Run As | `root` |
   | No Password | checked |
   | Command List | `/usr/local/libexec/nagios/check_pfsense.php` |

3. Check it from a shell on the firewall:

   ```sh
   sudo -u nagios /usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php list
   sudo -u nagios /usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php -v gateways
   ```

4. Configure the NRPE commands ([NRPE configuration](#nrpe-configuration)) and the Icinga 2 services ([Icinga 2](#icinga-2)).

Without root privileges every check returns:

```
UNKNOWN - This check must run as root: enable the Sudo option of the NRPE command (sudo package required)
```

After a pfSense upgrade, make sure that the plugin is still in `/usr/local/libexec/nagios`.

## Usage

```
check_pfsense.php [options] <subcommand> [subcommand options]
```

Options can be given **before or after** the subcommand. The NRPE package GUI builds the command as `<command> -w <Warning> -c <Critical> <Extra Options>`, so the subcommand goes in *Extra Options*, after `-w`/`-c`. These two command lines are equivalent:

```sh
check_pfsense.php -w 80 -c 90 states
check_pfsense.php states -w 80 -c 90
```

### Global options

| Option | Description |
|---|---|
| `-w`, `--warning RANGE` | Warning threshold; the meaning depends on the subcommand |
| `-c`, `--critical RANGE` | Critical threshold; the meaning depends on the subcommand |
| `-t`, `--timeout SEC` | Timeout of external commands and sockets (default: 10) |
| `-v`, `--verbose` | Add detail lines after the first line (long output) |
| `-h`, `--help` | Show the help, or the help of a subcommand (`states --help`) |
| `-V`, `--version` | Show the version |

Many subcommands accept `--include LIST` and `--exclude LIST`: comma separated glob patterns (`*`, `?`, case insensitive), repeatable. An item is checked if it matches an include pattern (or there are none) and no exclude pattern.

### Thresholds

`-w` and `-c` use the Nagios range syntax:

| Range | Alert when the value is |
|---|---|
| `10` | lower than 0 or greater than 10 |
| `10:` | lower than 10 |
| `~:10` | greater than 10 |
| `10:20` | outside 10..20 |
| `@10:20` | inside 10..20 |

For metrics where a low value is the problem (certificate days), a plain number `N` means `N:`. For signed metrics (NTP offset), it means `-N:N`. Incoherent thresholds, such as a warning greater than the critical, are usage errors.

### Output and exit codes

- One line on stdout: `STATE - message | perfdata`, then the long output with `-v`. Nothing is ever printed on stderr.
- Exit codes: 0 OK, 1 WARNING, 2 CRITICAL, 3 UNKNOWN.
- With many items the state is the worst one (CRITICAL > WARNING > UNKNOWN > OK). The message lists the items with problems first, then a summary (`3/4 gateways online`).
- Perfdata labels are quoted and sanitized (`[A-Za-z0-9_.-]`), with the units `s`, `ms`, `%`, `B`, `c` (counter) or none.
- Usage errors (unknown subcommand or option, invalid or incoherent thresholds, `-w`/`-c` given to a subcommand without thresholds) are **UNKNOWN**, never CRITICAL:

  ```
  UNKNOWN - Usage error: Subcommand 'gateways' does not use -w thresholds. See 'check_pfsense.php gateways --help'
  ```

## Subcommands

The default thresholds are used when `-w`/`-c` are not given. "Thresholds: none" means that `-w`/`-c` are rejected.

### gateways

Status of the gateways monitored by dpinger, like Status > Gateways.

| Gateway status (GUI) | State |
|---|---|
| Online, Online (unmonitored) | OK |
| Online (latency), Online (packet loss), Offline (forced) | WARNING |
| Offline, Offline (latency), Offline (packet loss) | CRITICAL |
| Pending (no dpinger status) | `--pending` (default UNKNOWN) |

- Thresholds: none. The dpinger thresholds of every gateway apply.
- Options: `--include`/`--exclude` (name or description), `--pending ok|warning|critical|unknown`.
- Perfdata: delay, stddev (ms) and loss (%) of every monitored gateway, with the dpinger thresholds.

```
CRITICAL - GW_WAN_1 offline (packet loss) (delay 0ms, loss 100%); 3/4 gateways online | 'GW_WAN_1_delay'=0ms;200;500;0 'GW_WAN_1_loss'=100%;10;20;0;100 ...
```

With `-v`:

```
OK - 4/4 gateways online | ...
[OK] VPN_M2M_VPNV4 (Interface VPN_M2M_VPNV4 Gateway, VPN_M2M, gateway 10.3.0.1): online (unmonitored)
[OK] GW_WAN_1 (WAN 1 gateway, WAN_1, gateway 203.0.113.153, monitor 192.0.2.53, default): online, delay 4.941ms, stddev 0.182ms, loss 0% (thresholds: delay 200/500ms, loss 10/20%)
```

### gateway-groups

Gateway groups and their members by tier. A member is usable according to the trigger level of the group (member down, packet loss, high latency, or both), like the gateway group routing of pfSense. Members with the gateway action disabled are always usable.

- CRITICAL if no member is usable. WARNING if a member is not usable, or if the group is not on its highest priority tier (failover).
- Thresholds: none. Options: `--include`/`--exclude` (group name or description).
- Perfdata: active tier (0 when none) and usable members of every group.

```
WARNING - WAN_FAILOVER failover to tier 2 (GW_WAN_1 offline (packet loss)); 1/2 gateway groups OK | 'WAN_FAILOVER_active_tier'=2;;;0 'WAN_FAILOVER_usable'=1;;;0;2 ...
```

### services

The services of Status > Services. A running service is OK, and a stopped service enabled in the configuration is CRITICAL. Services disabled in the configuration are not checked.

- Services are identified by name (`unbound`, `sshd`...), or `openvpn_<mode><vpnid>` (`openvpn_server1`) and `captiveportal_<zone>` for the services with more instances.
- Thresholds: none. Options: `--include`/`--exclude` (name or description).
- Perfdata: running and stopped services.

```
CRITICAL - unbound stopped; 9/10 services running (1 disabled not checked) | 'running'=9;;;0;10 'stopped'=1;;;0;10
```

### openvpn

The OpenVPN instances of Status > OpenVPN (disabled instances are skipped).

- Clients and peer-to-peer servers: CRITICAL if not connected, with the GUI status (example: `TLS Error, reconnecting`).
- Multipoint servers: CRITICAL if the daemon does not answer on the management socket; `-w`/`-c` apply to the connected clients.
- Thresholds: none by default. `-w`/`-c` are ranges on the connected clients (example: `-w 2:` warns below 2 clients); `--min-clients N` is the same as `-c N:`.
- Options: `--include`/`--exclude` (`server1`, `client2`, vpnid or description), `--mode server|client|all`, `--min-clients N`.
- Perfdata: connected clients and bytes in/out (counters) of every instance.

```
CRITICAL - client2 down (TLS Error, reconnecting); 2/3 OpenVPN instances OK | 'server1_clients'=2;;;0 'server1_bytes_in'=1050624c;;;0 ...
```

### ipsec

The enabled site-to-site IPsec tunnels.

- CRITICAL if the charon daemon is not running, if a phase 1 is not `ESTABLISHED`, or if an enabled phase 2 has no installed child SA. With IKEv2 without split connections, a single child SA covers all the phase 2 entries.
- Disabled entries and mobile clients are not checked.
- Thresholds: none. Options: `--include`/`--exclude` (`con1`, ikeid, description or remote gateway).
- Perfdata: connected phase 2 and bytes in/out (counters) of every tunnel.

```
CRITICAL - con2 1/2 P2 connected (missing: VoIP B); 1/2 IPsec tunnels established | 'con1_p2'=2;;;0;2 ...
```

### wireguard

Age of the latest handshake of the enabled peers of the enabled tunnels (`wg show all dump`). Requires the WireGuard package.

- CRITICAL if a tunnel is not running, if a peer is not loaded, or if a peer never completed a handshake (unless `--ignore-never`).
- UNKNOWN if WireGuard is not installed.
- Thresholds: handshake age in seconds, default **300/600**. A peer without traffic and without persistent keepalive stops handshaking: raise the thresholds or set a keepalive.
- Options: `--include`/`--exclude` (tunnel name or description, peer description or public key), `--ignore-never`.
- Perfdata: handshake age (s) and rx/tx bytes (counters) of every peer.
- Private and preshared keys are removed from the `wg` output before any processing.

```
WARNING - tun_wg1/site-b last handshake 5m 1s ago; 2/3 WireGuard peers OK | 'tun_wg1_site-b_handshake_age'=301s;300;600;0 ...
```

### dns

Resolves a name with `drill`, by default through the local resolver.

- CRITICAL if the query fails, if the response code is not `NOERROR`, if there are no records of the requested type, or if an expected address is missing.
- Thresholds: query time in ms, default **500/2000**.
- Options: `--query NAME` (default `pfsense.org`), `--type a|aaaa`, `--server IP` (default `127.0.0.1`), `--expect IP[,IP...]`.
- Perfdata: query time and number of records.

```
OK - pfsense.org A @127.0.0.1: 198.51.100.80 in 23 ms | 'time'=23ms;500;2000;0 'answers'=1;;;0
```

### carp

CARP virtual IPs, like Status > CARP.

- CRITICAL if a VIP is `INIT` or not found, or if the VIPs are in mixed states (some MASTER, some BACKUP: the VIPs in the minority state are listed).
- WARNING if a VIP state differs from `--expect`, if the CARP demotion is greater than 0, if the persistent maintenance mode is enabled, or if CARP is disabled (`net.inet.carp.allow=0`).
- Without CARP VIPs: UNKNOWN, or OK with `--allow-none`.
- Thresholds: none. Options: `--expect master|backup|any` (default `any`), `--allow-none`.
- Perfdata: VIPs in MASTER, BACKUP and INIT state, demotion.

```
CRITICAL - VIPs in mixed states (2 MASTER, 1 BACKUP), LAN@2 BACKUP; 3 CARP VIPs: 2 MASTER, 1 BACKUP | 'master'=2;;;0;3 ...
```

### mbuf

Usage of the mbuf clusters, like the dashboard: allocated clusters (in use + cached) compared with the maximum (`netstat -m`).

- Thresholds: usage percentage, default **80/90**.
- Denied mbuf and jumbo cluster requests are a WARNING. These counters are since boot: `--ignore-denied` skips the warning and keeps the value in perfdata.
- Perfdata: allocated and in use clusters, usage percentage, denied requests (counter).

```
OK - mbuf clusters 0.2% used (2292/1000000, 1022 in use, 1270 cached) | 'clusters'=2292;;;0;1000000 'clusters_usage'=0.23%;80;90;0;100 ...
```

### states

Usage of the pf state table, compared with the kernel hard limit (`pfctl -si`, `pfctl -sm`). CRITICAL if pf is disabled.

- Thresholds: usage percentage, default **80/90**.
- Options: `--src-nodes` to check the source tracking table too, with the same thresholds.
- Perfdata: current states (with the limit as max) and usage percentage.

```
OK - states 0.4% used (1523/400000) | 'states'=1523;;;0;400000 'states_usage'=0.38%;80;90;0;100
```

### updates

Available system and package updates. WARNING if a newer version of the system or of a GUI package (`pfSense-pkg-*`) is available, UNKNOWN if the repository is not reachable.

- The check updates the repository catalog (`pkg-static update`, nothing else), then compares the versions with read-only `pkg` commands, with the same logic of `pfSense-upgrade -c` for the system version. It does not run `pfSense-upgrade`, which can also upgrade `pkg` itself and create system notices.
- The result is cached in `/var/db/check_pfsense/updates.json` (atomic writes).
- Thresholds: none. Options: `--cache-ttl SEC` (default 21600, 0 disables the cache), `--no-packages`.
- The catalog update can take a while: use `-t 50` (NRPE stops commands after 60 seconds) and a longer NRPE timeout on the Icinga side.
- Perfdata: system update available (0/1) and number of package updates.

```
WARNING - pfSense update available: 2.8.1 -> 2.8.2; package updates available: pfSense-pkg-nrpe 2.4.1_1 -> 2.4.2; 3/4 packages up to date | 'system_updates'=1;;;0;1 'package_updates'=1;;;0
```

### interfaces

Link status of the assigned and enabled interfaces. CRITICAL if an interface has no UP flag, has a link status other than active (example: `no carrier`), or does not exist. `running` and `associated` (wireless) count as up, like the dashboard widget.

- Thresholds: none. Options: `--include`/`--exclude` (name like `wan`/`opt1`, description, or physical interface).
- Perfdata: input/output errors and collisions (counters) of every interface.

```
CRITICAL - DMZ (igb2) no carrier; 3/4 interfaces up | 'WAN_in_errors'=0c;;;0 ...
```

### certificates

Expiration of the certificates of the Certificate Manager. CRITICAL if expired, WARNING if not valid yet. The message says where a certificate is used (WebGUI, users, OpenVPN, IPsec, Captive Portal, DNS Resolver, Kea, packages such as HAProxy), or that it is not used.

- Thresholds: days until the expiration, default **30/14**. A plain number `N` means "less than N days".
- Options: `--only-used`, `--include-ca`, `--include`/`--exclude` (description, subject CN or refid).
- Unused certificates are checked too: use `--only-used` to skip old unused certificates.
- Perfdata: days until the expiration of every certificate.

```
WARNING - www.example.com expires in 20 days (2026-11-26, used by HAProxy); 2/3 certificates OK, next expiry in 20 days (www.example.com) | 'webConfigurator_days'=200;30:;14: ...
```

### ntp

NTP synchronization (`ntpq -pnw`, like Status > NTP). CRITICAL if ntpd does not answer, has no peers or has no selected peer (active `*` or PPS `o`).

- Thresholds: offset of the selected peer in ms, default **100/500**. A plain number `N` means `-N:N`.
- Perfdata: offset, jitter, stratum of the firewall, peers, reachable peers, candidates.

```
OK - synchronized to 192.0.2.123 (stratum 2), offset -0.512 ms, 1 candidates, 3/4 peers reachable | 'offset'=-0.512ms;-100:100;-500:500 ...
```

### notices

System notices, the bell in the GUI. WARNING if there are notices, with the newest ones in the first line and all of them in the long output. Notices are only read, never removed.

- Thresholds: none. Options: `--max-items N` (default 3), `--include`/`--exclude` (notice id or category).
- Perfdata: number of notices.

```
WARNING - 4 system notices: 2026-11-06 09:40 [filter_load] There were error(s) loading the rules: ...; and 2 more | 'notices'=4;;;0
```

### crash

Crash reports not yet sent or deleted: WARNING if there are files in `/var/crash`, with the same filter of the GUI. With `--php-errors`, also WARNING if `/tmp/PHP_errors.log` is not empty, like the dashboard. Nothing is removed: use Diagnostics > Crash Reporter.

- Thresholds: none. Options: `--php-errors`.
- Perfdata: crash report files, PHP error log size.

```
WARNING - 2 crash report files in /var/crash (latest 2026-10-26 07:33: info.0, textdump.tar.0) | 'crash_files'=2;;;0
```

### temperature

Maximum temperature of the CPU cores (`dev.cpu.N.temperature`), or of the ACPI thermal zones when there are no CPU sensors, like the dashboard. UNKNOWN if no sensor is available: enable the thermal sensor driver (`coretemp` or `amdtemp`) in System > Advanced > Miscellaneous.

- Thresholds: Celsius, default **70/85**.
- Options: `--source auto|cpu|acpi|all`.
- Perfdata: maximum and every sensor (Celsius, without unit).

```
OK - Maximum temperature 51 C (cpu1), 4 sensors | 'max'=51;70;85 'cpu0'=46;70;85 ...
```

### zfs

Health and capacity of the ZFS pools (`zpool list`, `zpool status -x`).

- CRITICAL if a pool is not `ONLINE`. WARNING if an online pool is reported by `zpool status -x` (example: data errors).
- UNKNOWN on UFS installations.
- Thresholds: used capacity percentage, default **80/90**.
- Options: `--include`/`--exclude` (pool name).
- Perfdata: capacity, allocated bytes and fragmentation of every pool.

```
OK - pfSense 10% used (3 GiB of 28 GiB), ONLINE | 'pfSense_capacity'=10%;80;90;0;100 ...
```

### memory

Memory usage with the formula of the dashboard: inactive and laundry pages count as free, because they can be freed under pressure.

- Thresholds: usage percentage, default **80/90**.
- On ZFS installations the ARC is wired memory and counts as used. With a large ARC, raise the thresholds or limit the ARC (`vfs.zfs.arc_max`).
- Perfdata: usage percentage, and used, active, wired, inactive, laundry and free memory in bytes.

```
OK - Memory 27.6% used (2.1 GiB of 7.7 GiB; active 204.3 MiB, wired 1.9 GiB, inactive 1.2 GiB, laundry 4.7 MiB, free 4.3 GiB) | 'usage'=27.63%;80;90;0;100 ...
```

### haproxy

HAProxy backends and servers, from the stats socket (`show stat`). Requires the HAProxy package.

- CRITICAL if a backend is not UP (no server available) or if HAProxy is not running.
- WARNING if a server is `DOWN`, `MAINT`, `DRAIN`, `NOLB` or going down.
- With the package *carpdev* setting, HAProxy runs only on the CARP master, so a stopped HAProxy on the backup node is OK.
- UNKNOWN if the package is not installed or disabled.
- Thresholds: none. Options: `--include`/`--exclude` (backend name), `--frontends` (CRITICAL if a frontend is not OPEN, WARNING if FULL), `--socket PATH` (default `/tmp/haproxy.socket`).
- Perfdata: servers up and current sessions of every backend.

```
WARNING - backend web_backend web2 DOWN; 1/2 backends OK | 'web_backend_servers_up'=1;;;0;2 ...
```

### speedtest

Result of a speedtest cron job, with the same logic, options and output of [check_speedtest.py](https://github.com/mmartinello/check_speedtest-cli). The test is never run: the plugin reads the JSON written by `speedtest-cli --json` or by the Ookla `speedtest --format=json`.

| Option | Description |
|---|---|
| `-w`, `-c` | Download warning/critical level in Mbit/s (alarm below the level) |
| `-W`, `-C` | Upload warning/critical level in Mbit/s |
| `-m`, `-M` | Maximum download/upload speed in Mbit/s (perfdata) |
| `--max-age MINUTES` | UNKNOWN if the file is older |
| `--file PATH` | JSON file, default `/var/db/speedtest.json` |

- Thresholds: none by default.
- Perfdata: download and upload speed, ping.

Example cron job (Cron package, System > Cron), if `speedtest-cli` is installed on the firewall:

```
0 */4 * * *  root  /usr/local/bin/speedtest-cli --json > /var/db/speedtest.json.tmp && mv /var/db/speedtest.json.tmp /var/db/speedtest.json
```

```
WARNING - Download speed: 93.12 Mbit/sec [WARNING], Upload speed: 20.5 Mbit/sec, Ping: 12.35 ms (server: Example ISP, Milan) | 'download_speed'=93.12;100.0:;50.0:;0.0;1000.0 ...
```

## NRPE configuration

### NRPE package GUI

In **Services > NRPE**, add one command per check. The GUI builds the command line as `<Command> -w <Warning> -c <Critical> <Extra Options>`. Leave *Warning* and *Critical* empty for the subcommands without thresholds, otherwise they return a usage error.

| Name | Sudo | Command | Warning | Critical | Extra Options |
|---|---|---|---|---|---|
| `check_pfsense_gateways` | ✓ | `check_pfsense.php` | | | `gateways` |
| `check_pfsense_gateway_groups` | ✓ | `check_pfsense.php` | | | `gateway-groups` |
| `check_pfsense_services` | ✓ | `check_pfsense.php` | | | `services` |
| `check_pfsense_openvpn` | ✓ | `check_pfsense.php` | | | `openvpn` |
| `check_pfsense_ipsec` | ✓ | `check_pfsense.php` | | | `ipsec` |
| `check_pfsense_wireguard` | ✓ | `check_pfsense.php` | `300` | `600` | `wireguard` |
| `check_pfsense_dns` | ✓ | `check_pfsense.php` | `500` | `2000` | `dns` |
| `check_pfsense_carp_master` | ✓ | `check_pfsense.php` | | | `carp --expect master` |
| `check_pfsense_carp_backup` | ✓ | `check_pfsense.php` | | | `carp --expect backup` |
| `check_pfsense_carp_any` | ✓ | `check_pfsense.php` | | | `carp --expect any` |
| `check_pfsense_mbuf` | ✓ | `check_pfsense.php` | `80` | `90` | `mbuf` |
| `check_pfsense_states` | ✓ | `check_pfsense.php` | `80` | `90` | `states` |
| `check_pfsense_updates` | ✓ | `check_pfsense.php` | | | `updates -t 50` |
| `check_pfsense_interfaces` | ✓ | `check_pfsense.php` | | | `interfaces` |
| `check_pfsense_certificates` | ✓ | `check_pfsense.php` | `30` | `14` | `certificates --only-used` |
| `check_pfsense_ntp` | ✓ | `check_pfsense.php` | `100` | `500` | `ntp` |
| `check_pfsense_notices` | ✓ | `check_pfsense.php` | | | `notices` |
| `check_pfsense_crash` | ✓ | `check_pfsense.php` | | | `crash` |
| `check_pfsense_temperature` | ✓ | `check_pfsense.php` | `70` | `85` | `temperature` |
| `check_pfsense_zfs` | ✓ | `check_pfsense.php` | `80` | `90` | `zfs` |
| `check_pfsense_memory` | ✓ | `check_pfsense.php` | `80` | `90` | `memory` |
| `check_pfsense_haproxy` | ✓ | `check_pfsense.php` | | | `haproxy` |
| `check_pfsense_speedtest` | ✓ | `check_pfsense.php` | `100` | `50` | `speedtest -W 20 -C 10 --max-age 300` |

Adapt the values to your setup: speedtest levels to your connection, `--include`/`--exclude` filters. The three CARP commands let the Icinga configuration choose the expected role of every node; define only the ones you use. Keep *Allow arguments* (`dont_blame_nrpe`) disabled: every command has fixed arguments.

### nrpe.cfg

The NRPE package writes `/usr/local/etc/nrpe.cfg` from the GUI settings, so edit the commands in the GUI. On other setups, or for reference, these are the equivalent lines:

```ini
command[check_pfsense_gateways]=/usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php gateways
command[check_pfsense_gateway_groups]=/usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php gateway-groups
command[check_pfsense_services]=/usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php services
command[check_pfsense_openvpn]=/usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php openvpn
command[check_pfsense_ipsec]=/usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php ipsec
command[check_pfsense_wireguard]=/usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php -w 300 -c 600 wireguard
command[check_pfsense_dns]=/usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php -w 500 -c 2000 dns
command[check_pfsense_carp_master]=/usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php carp --expect master
command[check_pfsense_carp_backup]=/usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php carp --expect backup
command[check_pfsense_carp_any]=/usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php carp --expect any
command[check_pfsense_mbuf]=/usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php -w 80 -c 90 mbuf
command[check_pfsense_states]=/usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php -w 80 -c 90 states
command[check_pfsense_updates]=/usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php updates -t 50
command[check_pfsense_interfaces]=/usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php interfaces
command[check_pfsense_certificates]=/usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php -w 30 -c 14 certificates --only-used
command[check_pfsense_ntp]=/usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php -w 100 -c 500 ntp
command[check_pfsense_notices]=/usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php notices
command[check_pfsense_crash]=/usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php crash
command[check_pfsense_temperature]=/usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php -w 70 -c 85 temperature
command[check_pfsense_zfs]=/usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php -w 80 -c 90 zfs
command[check_pfsense_memory]=/usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php -w 80 -c 90 memory
command[check_pfsense_haproxy]=/usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php haproxy
command[check_pfsense_speedtest]=/usr/local/bin/sudo /usr/local/libexec/nagios/check_pfsense.php -w 100 -c 50 speedtest -W 20 -C 10 --max-age 300
```

## Icinga 2

[`icinga2/pfsense.conf`](icinga2/pfsense.conf) is an example configuration. It uses the `nrpe` CheckCommand of the Icinga Template Library and apply rules driven by host variables:

| Host variable | Description |
|---|---|
| `vars.os = "pfSense"` | Required: enables the pfSense services |
| `vars.pfsense_checks` | Subcommands to check, one service `pfsense-<subcommand>` each (default: `PfSenseDefaultChecks`) |
| `vars.carp_role` | `master`, `backup` or `any`: adds the `pfsense-carp` service with the NRPE command `check_pfsense_carp_<role>` |
| `vars.pfsense_nrpe_timeout` | NRPE timeout in seconds (default 30; `updates` always uses 60) |

Every service calls the NRPE command `check_pfsense_<subcommand>`, with `-` replaced by `_`, so the NRPE command names must follow the table above. Slow checks have longer intervals: `updates` every hour, `certificates` every 6 hours.

```
object Host "fw01" {
  import "generic-host"
  address = "192.0.2.1"
  vars.os = "pfSense"
  vars.carp_role = "master"
  vars.pfsense_checks = [ "gateways", "services", "openvpn", "states", "updates" ]
}
```

## Read-only design

Before using a pfSense function, its source was read in `/etc/inc` and `/usr/local/www`. These are the functions with side effects, and what the plugin does instead:

| pfSense function | Side effect | What the plugin does |
|---|---|---|
| `get_gateways()` (2.9), `return_gateways_status()` | `refresh_gateways()` can call `write_config()` | `return_gateways_array()` and direct dpinger socket reads; the gateway cache is filled before `get_services()` |
| `return_gateway_groups_array()` | touches `/tmp/.down.*`, logs, sends remote notifications, calls package plugins | Same member selection logic in pure functions |
| `pfSense-upgrade -c`, `get_system_pkg_version()` | Can upgrade `pkg`, creates notices | Only `pkg-static update`, read-only version comparisons |
| `get_pkg_info()` | Marks the pkg subsystem dirty, runs `pfSense-repo-setup` | Not used |
| `get_notices()` | `notices_setup()` creates files and changes permissions | Reads the notice queue file directly |
| `get_interface_info()`, `get_carp_interface_status()` | Commands through `exec()` without timeout | `ifconfig` with timeout, same parsing |

Other rules:

- External commands run with `proc_open`, a timeout and escaped arguments.
- The plugin never prints PHP errors.
- Secrets (private keys, pre-shared keys, passwords) are never read or are removed immediately.
- During boot (`/var/run/booting`) the checks return UNKNOWN without loading the pfSense libraries.

## Testing

The evaluation logic is made of pure functions that receive data and return state, message and perfdata, so it is tested without pfSense:

```sh
php tests/run.php            # all tests
php tests/run.php gateways   # only the tests whose name contains "gateways"
```

Fixtures are in `tests/fixtures/`. [`tools/collect_fixtures.sh`](tools/collect_fixtures.sh) collects, on a firewall and read-only, the data used by every subcommand. It never collects keys, passwords or certificates. Review and anonymize the archive (IP addresses, names) before sharing it.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).
