# PRM Auto Deploy

Blueprint extension for Pterodactyl / Blueprint.

Create multiple game servers from the Admin → Servers page. Pick an egg and a donor install, then Auto Deploy opens N servers with shared game files via hardlink while keeping per-server `cfg` / addons independent.

## Version

`1.0.0`

## Notes

- Designed for large shared installs (for example CS2 on a host volume)
- Uses a small host helper for hardlink share when the panel PHP user cannot write Wings volumes
- Includes LAN-friendly startup cleanup for smoke / local testing

## Install

Copy into Blueprint extensions and run `blueprint -install prmautodeploy`.
