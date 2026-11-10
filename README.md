# PRM Auto Deploy

Blueprint extension for Pterodactyl / Blueprint.

Create multiple game servers from the Admin → Servers page. Pick an egg and a donor install, then Auto Deploy opens N servers with shared game files via hardlink while keeping per-server `cfg` / addons independent.

## Version

`1.1.0`

## Notes

- Designed for large shared installs (for example CS2 on a host volume)
- Uses a small host helper for hardlink share when the panel PHP user cannot write Wings volumes
- Donor lookup can run on the Wings node via `/lookup` when `/peto-volumes` is not mounted into the panel
- Allocations reuse the node's real IP and the next free port after existing allocations
- Share failures roll the new server record back instead of leaving an empty install
- `share.sh` must be LF; CRLF makes `sh` fail with `set: Illegal option -`
- Includes LAN-friendly startup cleanup for smoke / local testing

## Helper

```yaml
prm-share-helper:
  image: python:3.12-alpine
  restart: unless-stopped
  volumes:
    - /var/lib/pterodactyl/volumes:/data
    - ./extensions/prmautodeploy/helper.py:/helper.py:ro
    - ./extensions/prmautodeploy/share.sh:/share.sh:ro
  command: ["python", "/helper.py"]
```

Default URL: `http://prm-share-helper:8099`

On GKE / remote Wings, run the helper on the node and set `PRM_SHARE_HELPER_URL`, or keep port `8099` open so the panel can fall back to the node's FQDN.

## Install

Copy into Blueprint extensions and run `blueprint -install prmautodeploy`.
