## Install related packages
```bash
# Main pipewire audio stack
sudo pacman -S pipewire pipewire-alsa pipewire-pulse wireplumber

# alsamixer and other alsa tools
sudo pacman -S alsa-utils
```
- `pipewire`: The core audio service. It receives applications’ audio data and sends it to your speaker/headphone hardware.
- `wireplumber`: Session manager for pipewire. It remembers volume levels, switches between headphones/speakers, manages audio devices. Without it audio will break.
- `pipewire‑alsa`: Compatibility layer, lets old ALSA‑only programs work with PipeWire.
- `pipewire‑pulse`: Compatibility layer for old PulseAudio programs.
- `alsa‑utils`: Extra ALSA tools, contains `alsamixer`, `amixer`, `speaker‑test`. Not pre‑installed on minimal Arch.

## Check audio service status
```bash
systemctl --user status pipewire
```
- `systemctl`: service manager tool on Linux.
- `--user`: run for your current login user, not root system‑wide service. Audio services belong to user, not root.
- If output shows `active (running)`, pipewire works fine.

## wpctl (preferred daily volume tool for PipeWire)
```bash
# volume +5%
wpctl set-volume @DEFAULT_AUDIO_SINK@ 5%+
# volume -5%
wpctl set-volume @DEFAULT_AUDIO_SINK@ 5%-
# set exact volume
wpctl set-volume @DEFAULT_AUDIO_SINK@ 70%
# toggle mute
wpctl set-mute @DEFAULT_AUDIO_SINK@ toggle
# show current volume
wpctl get-volume @DEFAULT_AUDIO_SINK@
```
- `wpctl`: Command‑line control tool for PipeWire/Wireplumber. It does **not** exist in bare base Arch, comes with wireplumber package.
- `@DEFAULT_AUDIO_SINK@`: special built‑in placeholder, points to your currently active output device (speaker / headphone). You do not need to write real hardware name.
- `sink`: audio output device (sound goes out to hardware).
- `source`: audio input device (microphone, sound comes in).

## alsamixer
```bash
alsamixer
```
Keys inside UI:
- ↑ ↓ adjust volume
- ← → switch channels
- `M` mute / unmute
- `F6` switch sound‑card
- `q` quit

- `alsamixer`: Text‑UI mixer for ALSA subsystem.
- Important note: Under PipeWire, alsamixer does **not** talk directly to hardware. It sees PipeWire’s virtual audio device. It is good for debugging, use wpctl for daily operation.
- `MM` on screen = muted; `OO` = not muted.

## Test speakers
```bash
speaker-test -c2
# Ctrl+c to stop sound
```
- `speaker‑test`: generates test noise to check whether speakers work. `-c2` means 2‑channel stereo. Press `Ctrl + c` to terminate running program.

## Hyprland keybind example (add to hyprland.conf)
```ini
bindel=, XF86AudioRaiseVolume, exec, wpctl set-volume @DEFAULT_AUDIO_SINK@ 5%+
bindel=, XF86AudioLowerVolume, exec, wpctl set-volume @DEFAULT_AUDIO_SINK@ 5%-
bindl=, XF86AudioMute, exec, wpctl set-mute @DEFAULT_AUDIO_SINK@ toggle
```
Reload config after edit:
```bash
hyprctl reload
```
- `bindel`: Hyprland config keyword for media keys.
- `XF86AudioRaiseVolume`: standard keyboard key code for volume‑up media button.
- `exec`: run shell command when key presses.
- `hyprctl reload`: apply hyprland config without restart whole desktop.

## Common concepts summary
1. **PipeWire**: Modern Linux audio server, default for Hyprland. Replaces old PulseAudio. Handles all application audio.
2. **Wireplumber**: Manages PipeWire devices, remembers volume and device switching.
3. **wpctl**: User command tool to send instructions to PipeWire.
4. **ALSA**: Low‑level kernel layer that talks directly to sound hardware. PipeWire sits on top of ALSA.
5. `command not found`: The program belongs to some package, you have not installed that package yet. Use pacman to install it.