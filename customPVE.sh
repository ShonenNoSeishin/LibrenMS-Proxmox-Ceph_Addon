#!/bin/bash

parametre="$1"

# Vérifie que le paramètre est fourni
if [ -z "$parametre" ]; then
  echo "Usage: $0 <parametre>"
  exit 1
fi

# Construit le chemin complet
fichier="/usr/lib/PVE_INFO${parametre}.txt"

# Vérifie que le fichier existe
if [ ! -f "$fichier" ]; then
  echo "Fichier introuvable: $fichier"
  exit 2
fi

cat "$fichier"

