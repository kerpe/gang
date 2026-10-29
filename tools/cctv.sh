#!/bin/sh
# Turns a photo into the home page's CCTV look: a modern mid-range camera, slightly soft, muted colour, light grain.
# Usage: tools/cctv.sh source.png 01   -> public_html/img/cam-01.jpg and cam-01-sm.jpg (mobile)
set -e
src="$1"; out="$(dirname "$0")/../public_html/img/cam-$2"
convert -size 1600x3 xc:white -fill '#f2f2f2' -draw 'rectangle 0,0 1600,0' /tmp/cctv-line.png
convert "$src" -resize 1100x -quality 62 jpg:- \
  | convert - -resize 1600x1200^ -gravity center -extent 1600x1200 -modulate 103,72,100 -fill '#1f3326' -colorize 4% \
      -level 1%,97%,1.08 -attenuate 0.35 +noise Gaussian -blur 0x0.35 \
      \( -size 1600x1200 tile:/tmp/cctv-line.png \) -compose multiply -composite -strip -quality 72 "$out.jpg"
convert "$out.jpg" -resize 800x -quality 70 "$out-sm.jpg"
