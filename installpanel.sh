#!/data/data/com.termux/files/usr/bin/bash


# === KUNCI AKSES TERMUX & PEMBATAS USER ===
ACCESS_CODE="Xiters!"
ALLOWED_USERS=("u0_a306" "citergr2" "u0_a466" "root" "u0_a335")

read -p "Masukkan kunci akses: " input_code
if [[ "$input_code" != "$ACCESS_CODE" ]]; then
  echo "Kunci salah. Beli DONGO!!!"
  exit 1
fi

CURRENT_USER=$(whoami)
ALLOWED=false
for user in "${ALLOWED_USERS[@]}"; do
  if [[ "$CURRENT_USER" == "$user" ]]; then
    ALLOWED=true
    break
  fi
done

if [[ "$ALLOWED" == false ]]; then
  echo "Akses ditolak untuk user '$CURRENT_USER'"
  exit 1
fi
# === AKHIR KUNCI AKSES ===

# ANSI Color Codes
RED='\033[1;91m'
CYAN='\033[1;96m'
NC='\033[0m'

color() {
  case "$1" in
    red) echo -e "\033[1;91m$2\033[0m" ;;
    green) echo -e "\033[1;92m$2\033[0m" ;;
    yellow) echo -e "\033[1;93m$2\033[0m" ;;
    cyan) echo -e "\033[1;96m$2\033[0m" ;;
    *) echo "$2" ;;
  esac
}

install_panel_pterodactyl() {
  clear
  echo -e "\033[1;92m"
  figlet -f slant "PTERODACTYL PANEL"
  echo -e "\033[0m"
  echo -e "\033[1;97m╔════════════════════════════════════════════════════════════════════╗"
  echo -e "║                    \033[1;96mPANEL INSTALLER VPS - PTERODACTYL\033[1;97m               ║"
  echo -e "╠════════════════════════════════════════════════════════════════════╣"
  echo -e "║ \033[1;93m1.\033[0m Install Penuh (Panel + Wings)                                   ║"
  echo -e "║ \033[1;93m2.\033[0m Install Panel Saja                                              ║"
  echo -e "║ \033[1;93m3.\033[0m Install Wings (Node Agent) Saja                                 ║"
  echo -e "║ \033[1;93m0.\033[0m Kembali ke menu utama                                           ║"
  echo -e "╚════════════════════════════════════════════════════════════════════╝"
  echo ""

  read -p "Pilih opsi: " pilihan

  case "$pilihan" in
    1)
      color cyan "→ Memulai instalasi Panel & Wings..."
      bash <(curl -s https://raw.githubusercontent.com/guldkage/Pterodactyl-Installer/main/installer.sh)
      ;;
    2)
      color cyan "→ Memulai instalasi hanya Panel..."
      bash <(curl -s https://raw.githubusercontent.com/guldkage/Pterodactyl-Installer/main/installer.sh) --panel-only
      ;;
    3)
      color cyan "→ Memulai instalasi hanya Wings (Daemon)..."
      bash <(curl -s https://raw.githubusercontent.com/guldkage/Pterodactyl-Installer/main/installer.sh) --wings-only
      ;;
    0)
      return ;;
    *)
      color red "✗ Pilihan tidak valid!"
      ;;
  esac

  echo ""
  read -p "Tekan ENTER untuk kembali ke menu..."
}





show_ui_header() {
  clear
  local jam=$(date +"%H:%M:%S")

  echo -e "\033[1;96m╭──────────────────────────────────────────────────────────────────────────────────╮"
  printf "│ Jam: \033[1;93m%s\033[1;96m%*s          │\n" "$jam" $((72 - 6 - ${#jam})) ""
  echo    "│                                                                                  │"
  echo    "│        ██   ██ ██ ██████    ██████   ████      ████      ██       ██████         │"
  echo    "│         ██ ██  ██   ██        ██   ██    ██  ██    ██    ██     ██               │"
  echo    "│          ███   ██   ██        ██   ██    ██  ██    ██    ██       ██████         │"
  echo    "│         ██ ██  ██   ██        ██   ██    ██  ██    ██    ██             ██       │"
  echo    "│        ██   ██ ██   ██        ██     ████      ████      ██████  ███████         │"
  echo    "│                                                                                  │"
  echo -e "│                  \033[1;97mXITERS TOOLS\033[1;96m - All In One Security & Dev Toolset                │"
  echo -e "│                    \033[1;97mVersion 1.0.0\033[1;96m  |  by Xiters Official                          │"
  echo -e "╰──────────────────────────────────────────────────────────────────────────────────╯\033[0m"
}





# ========================
# Menu Utama
# ========================
while true; do
  show_ui_header
  echo -e "${RED}     ╭────────────────────────────── MENU ────────────────────────────────╮${NC}"
  echo -e "${CYAN}     │  1. INSTALL PANEL PTERODACTYL                                      │${NC}"
  echo -e "${RED}     ╰────────────────────────────────────────────────────────────────────╯${NC}"
  echo ""

  read -p "Pilih menu: " pilihan
  case "$pilihan" in
    1) install_panel_pterodactyl ;;
    0) color yellow "Keluar..."; exit 0 ;;
    *) color red "✗ Pilihan tidak valid!" ;;
  esac
  echo ""
  read -p "Tekan ENTER untuk kembali ke menu..."
done

