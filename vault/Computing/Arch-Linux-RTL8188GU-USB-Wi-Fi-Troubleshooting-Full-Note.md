## Document Overview
- Hardware: Realtek RTL8188GU USB Wi-Fi Adapter
- OS: Arch Linux
- Core Obstacle: The adapter boots into USB composite mass storage mode (virtual CD-ROM mode) by factory default
- Objective: Explain hardware mechanism, network interface names, full operation workflow and command functions

# 1. Basic Concept Explanation
## 1.1 Virtual CD-ROM Mode & USB Mode Switching
Many low-cost USB Wi-Fi adapters have a dual-mode firmware built in by manufacturers:
1. **Default Boot Mode: Mass Storage (Virtual CD-ROM / Virtual Disk)**
    - Designed for Windows users. When you plug the adapter in, the system detects it as a USB storage disc.
    - The virtual disc contains Windows driver installers.
    - Under this mode: The operating system **cannot detect the wireless hardware**. It only recognizes a storage device.
    - At the beginning of our troubleshooting, `lsusb` displayed `Product: DISK`, which represented this state.

2. **Working Mode: 802.11 Wi-Fi NIC Mode**
    - Special USB control messages must be sent to trigger the adapter mode switch.
    - After successful switching, the system identifies the hardware as a wireless network card.
    - After we finished switching, `lsusb` showed `0bda:c812 Realtek Semiconductor Corp. 802.11ac NIC`.

> Function of `usb-modeswitch`: This tool sends standardized USB commands to multi-function USB peripherals, switching them from storage mode to functional hardware mode. It is widely used for Wi-Fi adapters and 4G dongles.
> Important Note: For this RTL8188GU chip, the adapter only accepts switching commands within a short time window right after power-on. If the command arrives too late, the hardware ignores the request. Some hardware revisions can only permanently disable virtual CD-ROM mode under Windows.

## 1.2 Explanation of Network Interface Names (from `ip link`)
```
1: lo: <LOOPBACK,UP,LOWER_UP>
```
- `lo` = Loopback interface
- A virtual internal network interface used for local communication on your own computer.
- Traffic on lo never leaves your machine. Common usage: local program testing, localhost service access.

```
2: enp4s0: <BROADCAST,MULTICAST,UP,LOWER_UP>
```
- `en` = Ethernet (wired network)
- `p4s0` = PCI bus location fixed naming rule used by systemd
- This is your built-in wired Ethernet port (LAN cable connection).

```
4: wlan0: <NO-CARRIER,BROADCAST,MULTICAST,UP>
```
- `wlan` = Wireless Local Area Network (Wi-Fi interface)
- `wlan0` = The first detected wireless adapter
- `NO-CARRIER` = Hardware is recognized and driver loaded, but no Wi-Fi access point is connected yet. This is not an error.

## 1.3 Two Network Connection Types
### Wired Network (Ethernet / enp4s0)
- Data transfers via physical network cable.
- Advantages: Stable signal, low latency, less interference, consistent speed.
- Disadvantages: Limited by cable length; low mobility.

### Wireless Network (Wi-Fi / wlan0)
- Data transfers via radio frequency signals (802.11 protocol).
- Advantages: No physical cable; free movement.
- Disadvantages: Vulnerable to wall obstruction, signal interference; latency and speed fluctuate.

# 2. Full Timeline & Command Walkthrough
## Stage 1: Initial Problem
After plugging in the USB Wi-Fi adapter, `ip link` showed no `wlan0`.
`lsusb` listed `0bda:1a2b Realtek DISK`
Root cause: The adapter stayed locked in virtual CD-ROM storage mode, Linux could not see wireless hardware.

## Stage 2: Attempt to Use official repository `usb-modeswitch`
The mirror source issue caused `pacman -S usb-modeswitch` to return "package not found".
We abandoned repository installation and compiled `usb-modeswitch` from official source code.

### Related Commands
```bash
# Install compilation dependencies
sudo pacman -S base-devel libusb-compat
# Download source archive
wget https://www.draisberghof.de/usb_modeswitch/usb-modeswitch-2.6.2.tar.bz2
# Extract source code
tar -xf usb-modeswitch-2.6.2.tar.bz2
cd usb-modeswitch-2.6.2
# Compile binary
make
# Install program to system directory
sudo make install
```

## Stage 3: Execute USB Mode Switch
Critical timing rule: Unplug adapter → wait 5 seconds → reinsert into rear motherboard USB port → immediately execute command.
```bash
sudo usb_modeswitch -KW -v 0bda -p 1a2b
```
- `-v 0bda`: Specify vendor ID of Realtek
- `-p 1a2b`: Specify original product ID in virtual disk mode
- `-KW`: Eject storage partition and send switching signal

After execution: `lsusb` updated device ID to `0bda:c812`. Mode switch completed successfully.

## Stage 4: Compile & Install RTL8188GU Wi-Fi Driver
The Linux kernel does not include native driver for RTL8188GU. We built driver from open-source repository.
```bash
git clone https://github.com/McMCCRU/rtl8188gu.git
cd rtl8188gu
make
sudo make install
```
After installation, reboot the machine.

## Stage 5: Verify Hardware Recognition
```bash
ip link
```
Result: `wlan0` appeared, confirming driver loaded successfully.

## Stage 6: Connect Wi-Fi via iwctl (iwd network stack)
```bash
iwctl                     # Enter interactive wireless management shell
device list               # List all wireless adapters
station wlan0 scan        # Trigger scan for nearby Wi-Fi routers
station wlan0 get-networks # Show scanned Wi-Fi names
station wlan0 connect [SSID] # Connect to target Wi-Fi, enter password when prompted
exit                      # Exit iwctl shell
ping www.baidu.com        # Test internet connectivity
```

# 3. Important Known Limitations & Reminders
1. After Linux kernel updates, manually compiled RTL8188GU driver will stop working. You need to re-run `make && sudo make install` inside the driver folder.
2. After successful mode switch, the adapter retains Wi-Fi mode after hot re-plugging. No need to run usb-modeswitch every time.
3. The Starship terminal warning seen on startup is only a theme configuration syntax error. It has no impact on network functions.
4. The shutdown blocking error about `gnupg` is independent of the Wi-Fi adapter. It is a systemd service timeout issue, which can be fixed by adjusting systemd stop timeout parameters.