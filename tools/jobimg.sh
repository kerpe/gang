#!/bin/sh
# Job photos by role (JOB_IMAGES in bootstrap.php): the same CCTV look as tools/cctv.sh, smaller files.
# Usage: tools/jobimg.sh source.png cafe   -> public_html/img/job-cafe.jpg (1200x900) and job-cafe-sm.jpg (640x480)
set -e
src="$1"; out="$(dirname "$0")/../public_html/img/job-$2"
convert -size 1200x3 xc:white -fill '#f2f2f2' -draw 'rectangle 0,0 1200,0' /tmp/cctv-line.png
convert "$src" -resize 1200x900^ -gravity center -extent 1200x900 -modulate 103,72,100 -fill '#1f3326' -colorize 4% \
    -level 1%,97%,1.08 -attenuate 0.35 +noise Gaussian -blur 0x0.35 \
    \( -size 1200x900 tile:/tmp/cctv-line.png \) -compose multiply -composite -strip -interlace Plane -quality 70 "$out.jpg"
convert "$out.jpg" -resize 640x -quality 68 "$out-sm.jpg"
