# Modell-Export: Detektor (601 Klassen) und Zweitstufe (1000 Klassen)

Die beiden Netze unter `apps/vision-hud/models/` sind **einmalig** aus den
Ultralytics-Gewichten nach TensorFlow.js umgewandelt worden. Der Weg braucht
Python, PyTorch und ein pinnedes TensorFlow – deshalb passiert er nicht beim
Bauen (`tools/vendor.mjs` kopiert nur), sondern hier beschrieben, damit er
wiederholbar bleibt.

| Rolle | Gewichte | Eingabe | Ausgabe | Ergebnis (fp16) |
|---|---|---|---|---|
| Detektor | `yolov8n-oiv7.pt` (Open Images V7) | `[1, 512, 512, 3]` RGB 0…1 | `[1, 605, 5376]` | ≈ 7,0 MB |
| Zweitstufe | `yolov8n-cls.pt` (ImageNet) | `[1, 224, 224, 3]` RGB 0…1 | `[1, 1000]` Softmax | ≈ 5,4 MB |

Lizenz der Gewichte: AGPL-3.0 (Ultralytics). Die Klassenlisten stehen in
`site/assets/js/labels-oiv7.js` und `labels-imagenet.js` in Modellreihenfolge.

## Warum zwei Umgebungen

Der TF.js-Export von Ultralytics (`format='tfjs'`) ist in 8.4 durch LiteRT
ersetzt und lehnt `half=True` ab. Der stabile Weg ist ONNX → SavedModel
(onnx2tf) → TF.js (tensorflowjs_converter). onnx2tf und der Converter laufen
nur mit TensorFlow 2.15 und NumPy 1.x; Ultralytics will neuere Pakete.

## Schritt 1 – ONNX (Umgebung A)

```bash
python3.11 -m venv venv && venv/bin/pip install ultralytics==8.4.153 onnx onnxslim onnxruntime
curl -LO https://github.com/ultralytics/assets/releases/download/v8.3.0/yolov8n-oiv7.pt
curl -LO https://github.com/ultralytics/assets/releases/download/v8.3.0/yolov8n-cls.pt
venv/bin/python - <<'PY'
from ultralytics import YOLO
for weights, imgsz in [('yolov8n-oiv7.pt', 512), ('yolov8n-cls.pt', 224)]:
    YOLO(weights).export(format='onnx', imgsz=imgsz, opset=12, simplify=True, dynamic=False)
PY
```

Geprüft mit: ultralytics 8.4.153, torch 2.14.0, onnx 1.22.0, onnxslim 0.1.96.

## Schritt 2 – SavedModel und TF.js (Umgebung B)

```bash
python3.11 -m venv venv2
venv2/bin/pip install "tensorflow==2.15.1" "tensorflowjs==4.17.0" "onnx2tf==1.22.3" \
  "numpy==1.26.4" "onnx==1.16.1" onnxsim sng4onnx psutil ai-edge-litert flatbuffers
```

onnx2tf lädt beim Start eine Kalibrierdatei von GitHub herunter. Ist das Netz
gesperrt, kommt ein 404 als Datei an und `np.load` bricht ab. Die Datei ist
nur für INT8-Kalibrierung gedacht; für fp16 genügt Zufall mit der richtigen
Form:

```bash
venv2/bin/python -c "import numpy as np; np.save('calibration_image_sample_data_20x128x128x3_float32.npy', np.random.default_rng(7).random((20,128,128,3), dtype=np.float32))"
```

Umwandlung (im selben Ordner wie die `.npy`-Datei):

```bash
for name in yolov8n-oiv7 yolov8n-cls; do
  venv2/bin/onnx2tf -i "$name.onnx" -o "sm_$name" -osd -n
  venv2/bin/tensorflowjs_converter --input_format=tf_saved_model --output_format=tfjs_graph_model \
    --quantize_float16 "*" --control_flow_v2=True --strip_debug_ops=True "sm_$name" "tfjs_$name"
done
```

`-osd` schreibt das SavedModel mit Signatur, `-n` lässt die Eingabe in NHWC
(Kanäle hinten), so wie TF.js sie am schnellsten verarbeitet. `detector.js`
erkennt beide Anordnungen an der Eingabeform.

## Schritt 3 – ins Repository

```bash
cp tfjs_yolov8n-oiv7/{model.json,*.bin} apps/vision-hud/models/detector/
cp tfjs_yolov8n-cls/{model.json,*.bin}  apps/vision-hud/models/classifier/
node apps/vision-hud/tools/vendor.mjs     # kopiert nach site/assets/models/
node apps/vision-hud/tools/check.mjs      # prüft Manifeste und Dateiendungen
```

Die Gewichtsdateien heissen `group1-shard1of2.bin` – **mit** Endung. Dateien
ohne Endung blockieren manche Hoster (das war der Grund für „Objektmodell
nicht verfügbar“ in der ersten Fassung).

## Prüfen

`model.json` muss enthalten:

- Detektor: `inputs.images` `[1,512,512,3]`, `outputs.output0` `[1,605,5376]`
- Zweitstufe: `inputs.images` `[1,224,224,3]`, `outputs.output0` `[1,1000]`
- Operatoren nur aus: Conv2D/_FusedConv2D, MaxPool, Sigmoid, Softmax, Mul,
  AddV2, Sub, ConcatV2, SplitV, Reshape, Transpose, Pad, ResizeNearestNeighbor,
  StridedSlice, Mean, _FusedMatMul – alles, was der WebGL-Backend von TF.js 4.22 kann.

## Rückfall ONNX

Scheitert die Umwandlung, funktioniert die Seite auch mit den ONNX-Dateien:
`model.onnx` in denselben Ordner legen und `onnxruntime-web` (1.30) nach
`site/assets/vendor/ort/` (`ort.min.js`, `ort-wasm-simd-threaded.wasm` und
`.mjs`). `detector.js` und `classifier.js` wechseln von selbst, wenn kein
`model.json` erreichbar ist. Kostet 14 MB mehr und ist auf iOS langsamer.
