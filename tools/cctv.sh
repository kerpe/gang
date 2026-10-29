#!/bin/sh
# Turns a photo into the home page's CCTV look: low resolution, washed-out colour, grain, scan lines.
# Usage: tools/cctv.sh source.png 01   -> public_html/img/cam-01.jpg and cam-01-sm.jpg (mobile)
set -e
src="$1"; out="$(dirname "$0")/../public_html/img/cam-$2"
convert -size 1600x3 xc:white -fill '#dcdcdc' -draw 'rectangle 0,0 1600,0' /tmp/cctv-line.png
convert "$src" -resize 620x -quality 35 jpg:- \
  | convert - -resize 1600x1200^ -gravity center -extent 1600x1200 -modulate 104,45,100 -fill '#1f3326' -colorize 8% \
      -level 2%,94%,1.18 -attenuate 1.0 +noise Gaussian -blur 0x0.6 \
      \( -size 1600x1200 tile:/tmp/cctv-line.png \) -compose multiply -composite -strip -quality 52 "$out.jpg"
convert "$out.jpg" -resize 800x -quality 58 "$out-sm.jpg"
