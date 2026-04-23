#!/usr/bin/env python3
import json
import os
import pathlib

SCRIPT_DIR = pathlib.Path(__file__).resolve().parent
DEFAULT_ROOT = SCRIPT_DIR.parent
WEB_ADMIN_ROOT = pathlib.Path(os.environ.get("WEB_ADMIN_ROOT") or DEFAULT_ROOT)
STATE_DIR = pathlib.Path(os.environ.get("STATE_DIR") or (WEB_ADMIN_ROOT / "state"))
STATUS_PATH = STATE_DIR / "backup_status.json"
OUT_PATH = STATE_DIR / "backup_disk.png"

def main():
    try:
        with STATUS_PATH.open("r", encoding="utf-8") as f:
            data = json.load(f)
    except Exception as e:
        # Silent fail; script is best-effort
        print(f"failed to read {STATUS_PATH}: {e}")
        return

    disk = data.get("disk", {})
    usage_pct = float(disk.get("usage_percent") or 0)
    total_gb = disk.get("total_gb")
    folders = disk.get("folders") or []

    labels = []
    sizes = []
    colors = []

    folder_mode = bool(total_gb) and len(folders) > 0

    if folder_mode:
        palette = ["#42a5f5", "#66bb6a", "#ffa726",
                   "#ab47bc", "#ec407a", "#26c6da"]
        used_sum = 0.0
        for idx, folder in enumerate(folders):
            size = float(folder.get("size_gb") or 0)
            used_sum += size
            labels.append(folder.get("label") or f"Folder {idx+1}")
            sizes.append(size)
            colors.append(palette[idx % len(palette)])
        free = max(0.0, float(total_gb) - used_sum)
        if free > 0.01:
            labels.append("Free")
            sizes.append(free)
            colors.append("#4d7a3f")
        title = f"Backup disk – total {float(total_gb):.0f} GB"
    else:
        used = usage_pct
        free = max(0.0, 100.0 - used)
        labels = ["Used", "Free"]
        sizes = [used, free]
        colors = ["#42a5f5", "#66bb6a"]
        title = f"Backup disk – {usage_pct:.1f}% used"

    import matplotlib
    matplotlib.use("Agg")
    import matplotlib.pyplot as plt

    fig, ax = plt.subplots(figsize=(5.0, 3.0), dpi=160)
    fig.patch.set_facecolor("#0b0c10")
    ax.set_facecolor("#0b0c10")

    wedges, _ = ax.pie(
        sizes,
        labels=None,
        colors=colors,
        startangle=90,
        wedgeprops=dict(width=0.35, edgecolor="#0b0c10"),
    )

    # Center text
    ax.text(
        0, 0,
        f"{usage_pct:.0f}%",
        ha="center",
        va="center",
        color="white",
        fontsize=14,
        fontweight="bold",
    )

    legend_labels = []
    if folder_mode and total_gb:
        total_gb_f = float(total_gb)
        for label, size in zip(labels, sizes):
            if label == "Free":
                pct = (size / total_gb_f) * 100 if total_gb_f else 0
                legend_labels.append(f"{label}: {size:.1f} GB ({pct:.1f}%)")
            else:
                pct = (size / total_gb_f) * 100 if total_gb_f else 0
                legend_labels.append(f"{label}: {size:.1f} GB ({pct:.1f}%)")
    else:
        for label, size in zip(labels, sizes):
            legend_labels.append(f"{label}: {size:.1f}%")

    ax.legend(
        wedges,
        legend_labels,
        loc="center left",
        bbox_to_anchor=(1.02, 0.5),
        frameon=False,
        labelcolor="#cfd8dc",
        fontsize=8,
    )

    ax.set_title(title, color="#cfd8dc", fontsize=10)
    plt.tight_layout()
    fig.savefig(OUT_PATH, facecolor=fig.get_facecolor(), bbox_inches="tight")
    plt.close(fig)

if __name__ == "__main__":
    main()
