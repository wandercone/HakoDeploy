# HakoDeploy

An Unraid plugin that configures and deploys [HakoFoundry](https://github.com/HakoForge/HakoFoundry). A server chassis companion app that displays hard drive SMART data, monitors power, and manages fan curves for the Hako-Core (Mini). To utilize power board features, a HakoForge Power Board is required on the HF-L1 chassis.

## Features

- Select block devices (HDD, SSD, NVMe) and USB serial adapters to pass through to the container
- Install the Docker template for use in Unraid's Docker Manager
- Deploy, stop, and restart the container directly from the UI
- Live container status polling

## Requirements

- Unraid 7.1.0 or later
- Docker / Community Applications plugin installed

## Installation

**Via Community Applications (recommended)**

Search for **HakoDeploy** in the Apps tab and install from there.

**Via Plugin URL**

1. In the Unraid UI go to **Plugins > Install Plugin**
2. Paste the `.plg` URL from the [latest release](https://github.com/wandercone/hakodeploy/releases/latest)
3. Click **Install**

After installation the plugin is available at **Tools > HakoDeploy**.

## Usage

1. **Select devices** — choose the storage drives and serial adapters to expose to HakoFoundry
2. **Configure** — set the web UI port, appdata path, user/group IDs, optional secret key, and other standard container settings
3. **Apply & Deploy Template** — writes the config and installs the Docker template

HakoFoundry will be accessible at `http://<server-ip>:<WEB_PORT>/` (default port 8080).

## Configuration

| Setting | Default | Description |
|---|---|---|
| Appdata Path | `/mnt/user/appdata/hako-foundry` | Persistent data directory (must be under `/mnt/`) |
| Web UI Port | `8080` | Host port mapped to the container |
| PUID | `99` | User ID for file permissions (nobody) |
| PGID | `100` | Group ID for file permissions (users) |
| Open Access | `false` | Skip authentication (not recommended) |
| Secret Key | auto-generated | Session secret; leave blank to generate on first apply |

## Caveats
This plugin currently recreates an xml and force inserts the required dockerMan labels and variables. A future version will ideally run the xml through dockerMan which will handle these required fields. 
