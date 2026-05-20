@echo off
REM Start the Smart Select inference sidecar (YOLO segmentation for the annotator).
REM Uses py -3 (Python 3.13) where ultralytics/torch/opencv are installed.
cd /d "%~dp0"
py -3 server.py
pause
