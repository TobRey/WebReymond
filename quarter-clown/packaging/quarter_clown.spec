# PyInstaller-Spezifikation für die Windows-App.
# Bauen:  pyinstaller packaging/quarter_clown.spec --noconfirm
from PyInstaller.utils.hooks import collect_data_files, collect_submodules

ROOT = SPECPATH + "/.."

datas = [(ROOT + "/quarter_clown/assets", "quarter_clown/assets")]
import glob, os
try:  # Haar-Kaskaden für die Gesichtserkennung
    import cv2
    datas += [(f, "cv2/data") for f in glob.glob(os.path.join(cv2.data.haarcascades, "*.xml"))]
except Exception:
    pass
try:
    datas += collect_data_files("soundcard")
except Exception:
    pass

hiddenimports = (
    collect_submodules("quarter_clown")
    + collect_submodules("anthropic")
    + ["soundcard", "obsws_python", "PIL.ImageTk"]
)

a = Analysis(
    [ROOT + "/packaging/launcher.py"],
    pathex=[ROOT],
    datas=datas,
    hiddenimports=hiddenimports,
    excludes=["quarter_clown.server", "pytest", "matplotlib"],
    noarchive=False,
)
pyz = PYZ(a.pure)
exe = EXE(
    pyz, a.scripts, [],
    exclude_binaries=True,
    name="QuarterClown",
    icon=ROOT + "/quarter_clown/assets/icon.ico",
    console=False,
    version=None,
)
coll = COLLECT(exe, a.binaries, a.datas, name="QuarterClown")
