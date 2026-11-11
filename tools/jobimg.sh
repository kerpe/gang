#!/bin/sh
# Job photos by role (JOB_IMAGES in bootstrap.php): the same CCTV look as tools/cctv.sh, smaller files.
# Usage: tools/jobimg.sh source.png cafe   -> public_html/img/job-cafe.jpg (960x720) and job-cafe-sm.jpg (640x480).
# Quality 55 with a little more grain: looks like a cheap camera and keeps files about half the size.
set -e
src="$1"; out="$(dirname "$0")/../public_html/img/job-$2"
convert -size 960x3 xc:white -fill '#f2f2f2' -draw 'rectangle 0,0 960,0' /tmp/cctv-line.png
convert "$src" -resize 960x720^ -gravity center -extent 960x720 -modulate 103,72,100 -fill '#1f3326' -colorize 4% \
    -level 1%,97%,1.08 -attenuate 0.45 +noise Gaussian -blur 0x0.35 \
    \( -size 960x720 tile:/tmp/cctv-line.png \) -compose multiply -composite -strip -interlace Plane -sampling-factor 4:2:0 -quality 55 "$out.jpg"
convert "$out.jpg" -resize 640x -quality 55 "$out-sm.jpg"
