from __future__ import annotations

from copy import deepcopy
from pathlib import Path
import sys
import zipfile
import xml.etree.ElementTree as ET


W = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
R = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
WP = "http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
A = "http://schemas.openxmlformats.org/drawingml/2006/main"
PIC = "http://schemas.openxmlformats.org/drawingml/2006/picture"
REL = "http://schemas.openxmlformats.org/package/2006/relationships"

for prefix, uri in [("w", W), ("r", R), ("wp", WP), ("a", A), ("pic", PIC)]:
    ET.register_namespace(prefix, uri)


def qn(ns: str, tag: str) -> str:
    return f"{{{ns}}}{tag}"


def w_el(tag: str, text: str | None = None, **attrs: str | int) -> ET.Element:
    el = ET.Element(qn(W, tag), {qn(W, k): str(v) for k, v in attrs.items()})
    if text is not None:
        el.text = text
    return el


def cm_to_emu(cm: float) -> int:
    return int(cm * 360000)


def run(text: str, *, bold: bool = False, italic: bool = False, size: int | None = None) -> ET.Element:
    run_el = w_el("r")
    rpr = w_el("rPr")
    if bold:
        rpr.append(w_el("b"))
    if italic:
        rpr.append(w_el("i"))
    if size is not None:
        rpr.append(w_el("sz", val=size))
        rpr.append(w_el("szCs", val=size))
    if len(rpr):
        run_el.append(rpr)

    text_el = w_el("t")
    if text.startswith(" ") or text.endswith(" "):
        text_el.set("{http://www.w3.org/XML/1998/namespace}space", "preserve")
    text_el.text = text
    run_el.append(text_el)
    return run_el


def paragraph(
    text: str = "",
    *,
    style: str | None = None,
    align: str | None = None,
    bold: bool = False,
    italic: bool = False,
    size: int | None = None,
    spacing_after: int | None = 120,
) -> ET.Element:
    p = w_el("p")
    ppr = w_el("pPr")
    if style:
        ppr.append(w_el("pStyle", val=style))
    if align:
        ppr.append(w_el("jc", val=align))
    if spacing_after is not None:
        ppr.append(w_el("spacing", after=spacing_after, line=276, lineRule="auto"))
    if len(ppr):
        p.append(ppr)
    if text:
        p.append(run(text, bold=bold, italic=italic, size=size))
    return p


def page_break() -> ET.Element:
    p = w_el("p")
    r = w_el("r")
    r.append(w_el("br", type="page"))
    p.append(r)
    return p


def heading(text: str, level: int = 1) -> ET.Element:
    if level == 1:
        return paragraph(text, style="Heading1", bold=True, size=30, spacing_after=180)
    if level == 2:
        return paragraph(text, style="Heading2", bold=True, size=26, spacing_after=140)
    return paragraph(text, style="Heading3", bold=True, size=23, spacing_after=100)


def bullet(text: str) -> ET.Element:
    return paragraph("• " + text, spacing_after=80)


def numbered(text: str, idx: int) -> ET.Element:
    return paragraph(f"{idx}. {text}", spacing_after=80)


def table(rows: list[list[str]], *, header: bool = True) -> ET.Element:
    tbl = w_el("tbl")
    tbl_pr = w_el("tblPr")
    tbl_pr.append(w_el("tblW", w="0", type="auto"))

    borders = w_el("tblBorders")
    for side in ["top", "left", "bottom", "right", "insideH", "insideV"]:
        borders.append(w_el(side, val="single", sz="6", space="0", color="BFBFBF"))
    tbl_pr.append(borders)
    tbl.append(tbl_pr)

    for row_idx, row in enumerate(rows):
        tr = w_el("tr")
        for cell in row:
            tc = w_el("tc")
            tcpr = w_el("tcPr")
            if header and row_idx == 0:
                tcpr.append(w_el("shd", fill="D9EAF7"))
            tc.append(tcpr)
            tc.append(paragraph(str(cell), bold=(header and row_idx == 0), spacing_after=0))
            tr.append(tc)
        tbl.append(tr)
    return tbl


def image_paragraph(
    image_rids: dict[str, str],
    filename: str,
    width_cm: float,
    height_cm: float,
    name: str,
    docpr_id: int,
) -> ET.Element | None:
    rid = image_rids.get(filename)
    if not rid:
        return None

    cx = cm_to_emu(width_cm)
    cy = cm_to_emu(height_cm)
    p = paragraph(align="center", spacing_after=80)
    r = w_el("r")
    drawing = ET.Element(qn(W, "drawing"))
    inline = ET.SubElement(
        drawing,
        qn(WP, "inline"),
        {"distT": "0", "distB": "0", "distL": "0", "distR": "0"},
    )
    ET.SubElement(inline, qn(WP, "extent"), {"cx": str(cx), "cy": str(cy)})
    ET.SubElement(inline, qn(WP, "effectExtent"), {"l": "0", "t": "0", "r": "0", "b": "0"})
    ET.SubElement(inline, qn(WP, "docPr"), {"id": str(docpr_id), "name": name})
    c_nv = ET.SubElement(inline, qn(WP, "cNvGraphicFramePr"))
    ET.SubElement(c_nv, qn(A, "graphicFrameLocks"), {"noChangeAspect": "1"})
    graphic = ET.SubElement(inline, qn(A, "graphic"))
    graphic_data = ET.SubElement(
        graphic,
        qn(A, "graphicData"),
        {"uri": "http://schemas.openxmlformats.org/drawingml/2006/picture"},
    )
    pic = ET.SubElement(graphic_data, qn(PIC, "pic"))
    nv = ET.SubElement(pic, qn(PIC, "nvPicPr"))
    ET.SubElement(nv, qn(PIC, "cNvPr"), {"id": str(docpr_id), "name": filename})
    ET.SubElement(nv, qn(PIC, "cNvPicPr"))
    blip_fill = ET.SubElement(pic, qn(PIC, "blipFill"))
    ET.SubElement(blip_fill, qn(A, "blip"), {qn(R, "embed"): rid})
    stretch = ET.SubElement(blip_fill, qn(A, "stretch"))
    ET.SubElement(stretch, qn(A, "fillRect"))
    sppr = ET.SubElement(pic, qn(PIC, "spPr"))
    xfrm = ET.SubElement(sppr, qn(A, "xfrm"))
    ET.SubElement(xfrm, qn(A, "off"), {"x": "0", "y": "0"})
    ET.SubElement(xfrm, qn(A, "ext"), {"cx": str(cx), "cy": str(cy)})
    geom = ET.SubElement(sppr, qn(A, "prstGeom"), {"prst": "rect"})
    ET.SubElement(geom, qn(A, "avLst"))
    r.append(drawing)
    p.append(r)
    return p


def read_template_parts(src: Path) -> tuple[ET.Element | None, dict[str, str]]:
    with zipfile.ZipFile(src, "r") as zin:
        old_doc = ET.fromstring(zin.read("word/document.xml"))
        old_body = old_doc.find(qn(W, "body"))
        old_sect = None
        if old_body is not None:
            for child in list(old_body):
                if child.tag == qn(W, "sectPr"):
                    old_sect = deepcopy(child)

        rels_root = ET.fromstring(zin.read("word/_rels/document.xml.rels"))
        image_rids: dict[str, str] = {}
        for rel in rels_root.findall(qn(REL, "Relationship")):
            target = rel.attrib.get("Target", "")
            rid = rel.attrib.get("Id")
            if target.startswith("media/") and rid:
                image_rids[Path(target).name] = rid
    return old_sect, image_rids


def build_blocks(image_rids: dict[str, str]) -> list[ET.Element]:
    blocks: list[ET.Element] = []
    add = blocks.append

    def add_image(filename: str, width_cm: float, height_cm: float, caption: str | None, docpr_id: int) -> None:
        img = image_paragraph(image_rids, filename, width_cm, height_cm, caption or filename, docpr_id)
        if img is not None:
            add(img)
            if caption:
                add(paragraph(caption, align="center", italic=True))

    add_image("image1.jpg", 14.5, 4.65, None, 1)
    add(paragraph("BỘ GIÁO DỤC VÀ ĐÀO TẠO", align="center", bold=True, size=24))
    add(paragraph("TRƯỜNG ĐẠI HỌC CÔNG NGHỆ TP. HỒ CHÍ MINH", align="center", bold=True, size=24))
    add(paragraph("", spacing_after=180))
    add(paragraph("BÁO CÁO ĐỒ ÁN CƠ SỞ", align="center", bold=True, size=36, spacing_after=180))
    add(
        paragraph(
            "XÂY DỰNG WEBSITE DỰ ĐOÁN THUỐC ĐIỀU TRỊ BỆNH DỰA TRÊN MẠNG LIÊN KẾT THUỐC - PROTEIN - BỆNH",
            align="center",
            bold=True,
            size=30,
            spacing_after=240,
        )
    )
    add(paragraph("Ngành: Công Nghệ Thông Tin", align="center", size=24))
    add(paragraph("Chuyên ngành: Công Nghệ Phần Mềm", align="center", size=24))
    add(paragraph("Giảng viên hướng dẫn: GV. Nguyễn Hữu Trung", align="center", size=24))
    add(paragraph("Sinh viên thực hiện: Phan Trần Đức Trí - Huỳnh Tuấn Kiệt", align="center", size=24))
    add(paragraph("TP. Hồ Chí Minh, 2026", align="center", size=24))
    add(page_break())

    add(heading("LỜI MỞ ĐẦU", 1))
    add(
        paragraph(
            "Trong bối cảnh y học và công nghệ sinh học phát triển nhanh, việc nghiên cứu một loại thuốc mới từ đầu thường đòi hỏi chi phí lớn và thời gian thử nghiệm dài. Hướng tái sử dụng thuốc (drug repurposing) giúp rút ngắn quá trình này bằng cách tìm kiếm công dụng điều trị mới cho các thuốc đã biết."
        )
    )
    add(
        paragraph(
            "Đồ án lựa chọn bài toán dự đoán liên kết Thuốc - Bệnh (Drug-Disease Association) dựa trên mạng sinh học không đồng nhất gồm thuốc, bệnh và protein. Trên nền mô hình AMDGT, nhóm xây dựng thêm pipeline huấn luyện, tầng suy luận bằng FastAPI và website PHP/MySQL để người dùng có thể chọn dữ liệu, chạy dự đoán, xem kết quả, so sánh hai mô hình và quản lý lịch sử tra cứu."
        )
    )
    add(
        paragraph(
            "Báo cáo này đã được chỉnh lại để bám sát mã nguồn hiện có trong dự án: phần mô hình trình bày đúng các module đã triển khai; phần hệ thống nêu rõ kiến trúc PHP - MySQL - FastAPI; phần kết quả được diễn đạt thận trọng, kèm lưu ý cần lưu trữ log/CSV/checkpoint làm minh chứng khi nộp bản cuối."
        )
    )
    add(page_break())

    add(heading("LỜI CẢM ƠN", 1))
    add(
        paragraph(
            "Nhóm chúng em xin gửi lời cảm ơn đến quý thầy cô Khoa Công Nghệ Thông Tin, Trường Đại học Công nghệ TP. Hồ Chí Minh đã hỗ trợ kiến thức nền tảng để nhóm thực hiện đồ án này."
        )
    )
    add(
        paragraph(
            "Đặc biệt, nhóm chúng em xin cảm ơn GV. Nguyễn Hữu Trung đã hướng dẫn, góp ý và tạo điều kiện để nhóm hoàn thiện đề tài theo hướng vừa có nền tảng thuật toán, vừa có sản phẩm website có thể trình diễn."
        )
    )
    add(
        paragraph(
            "Do thời gian và kinh nghiệm còn hạn chế, báo cáo khó tránh khỏi thiếu sót. Nhóm chúng em mong nhận được góp ý từ thầy cô để tiếp tục hoàn thiện đề tài."
        )
    )
    add(page_break())

    add(heading("LỜI CAM ĐOAN", 1))
    add(
        paragraph(
            "Nhóm chúng em cam đoan đồ án “Xây dựng website dự đoán thuốc điều trị bệnh dựa trên mạng liên kết thuốc - protein - bệnh” là sản phẩm do nhóm thực hiện trong quá trình học tập và nghiên cứu. Các tài liệu, bài báo và mã nguồn tham khảo đều được ghi nhận trong phần tài liệu tham khảo."
        )
    )
    add(
        paragraph(
            "Nhóm chúng em chịu trách nhiệm về tính trung thực của nội dung báo cáo, kết quả thực nghiệm và phần sản phẩm được trình bày."
        )
    )
    add(page_break())

    add(heading("MỤC LỤC", 1))
    for item in [
        "Chương 1. Tổng quan đề tài",
        "Chương 2. Cơ sở lý thuyết và mô hình cải tiến",
        "Chương 3. Phân tích, thiết kế và triển khai hệ thống",
        "Chương 4. Thực nghiệm, kết quả và sản phẩm minh họa",
        "Tài liệu tham khảo",
    ]:
        add(paragraph(item))
    add(page_break())

    add(heading("CHƯƠNG 1. TỔNG QUAN ĐỀ TÀI", 1))
    add(heading("1.1. Lý do chọn đề tài", 2))
    add(
        paragraph(
            "Mạng liên kết Thuốc - Protein - Bệnh chứa nhiều quan hệ sinh học quan trọng. Nếu biểu diễn các quan hệ này dưới dạng đồ thị không đồng nhất, mô hình học sâu trên đồ thị có thể khai thác cả đặc trưng của từng thực thể lẫn cấu trúc liên kết giữa các thực thể. Đây là cơ sở để dự đoán những liên kết Thuốc - Bệnh tiềm năng, phục vụ bài toán tái sử dụng thuốc."
        )
    )
    add(
        paragraph(
            "Ngoài phần thuật toán, đề tài hướng đến một hệ thống có thể sử dụng và trình diễn được. Vì vậy, nhóm không chỉ tái hiện mô hình nghiên cứu AMDGT mà còn xây dựng website, API suy luận và cơ sở dữ liệu quản lý người dùng, lịch sử dự đoán, danh mục thuốc, bệnh và liên kết sinh học."
        )
    )
    add(heading("1.2. Mục tiêu của đề tài", 2))
    for item in [
        "Tìm hiểu mô hình AMDGT và bài toán dự đoán liên kết Thuốc - Bệnh trên đồ thị không đồng nhất.",
        "Bổ sung các hướng cải tiến ở mức biểu diễn như topology view, multi-view aggregation, contrastive alignment và pair scoring linh hoạt.",
        "Xây dựng pipeline huấn luyện có preset theo dataset, logging, checkpoint, early stopping và các cơ chế ổn định hóa quá trình học.",
        "Triển khai FastAPI làm tầng suy luận để website PHP có thể gọi mô hình qua HTTP.",
        "Xây dựng giao diện web hỗ trợ dự đoán, so sánh mô hình, trực quan hóa mạng liên kết, lưu lịch sử và quản trị dữ liệu.",
    ]:
        add(bullet(item))
    add(heading("1.3. Phạm vi thực hiện", 2))
    add(
        paragraph(
            "Phạm vi đồ án gồm ba phần chính: phần nghiên cứu và cải tiến mô hình, phần API suy luận, và phần website quản lý/dự đoán. Hệ thống phục vụ mục tiêu học tập, nghiên cứu và demo; kết quả dự đoán chỉ mang tính hỗ trợ, không thay thế kiểm chứng y sinh hoặc chỉ định điều trị thực tế."
        )
    )
    add(heading("1.4. Công nghệ sử dụng", 2))
    add(
        table(
            [
                ["Thành phần", "Công nghệ / file tiêu biểu", "Vai trò"],
                ["Mô hình AI", "PyTorch, DGL, model/improved/*.py", "Huấn luyện và suy luận mô hình HGT cải tiến"],
                ["Tiền xử lý", "data_preprocess_improved.py, topology_features.py", "Đọc dữ liệu, tạo graph, trích đặc trưng topology"],
                ["API", "FastAPI, python_api/main.py", "Cung cấp /health, /predict, /predict_pairs, /entities"],
                ["Web", "PHP, HTML, CSS, JavaScript", "Dashboard, lịch sử, quản trị, trực quan hóa"],
                ["Cơ sở dữ liệu", "MySQL, database/database_schema.sql", "Lưu người dùng, thực thể sinh học, liên kết và lịch sử"],
                ["Trực quan hóa", "3d-force-graph, Three.js, SmilesDrawer", "Hiển thị graph 2D/3D và cấu trúc phân tử"],
            ]
        )
    )
    add(page_break())

    add(heading("CHƯƠNG 2. CƠ SỞ LÝ THUYẾT VÀ MÔ HÌNH CẢI TIẾN", 1))
    add(heading("2.1. Bài toán dự đoán liên kết Thuốc - Bệnh", 2))
    add(
        paragraph(
            "Bài toán Drug-Disease Association Prediction xem mỗi cặp thuốc và bệnh như một ứng viên liên kết. Mục tiêu của mô hình là học từ các liên kết đã biết để ước lượng xác suất một thuốc có khả năng liên quan đến một bệnh. Dữ liệu đầu vào gồm đặc trưng thuốc, đặc trưng bệnh, đặc trưng protein và các quan hệ đã biết giữa ba nhóm thực thể."
        )
    )
    add(heading("2.2. Mô hình AMDGT gốc", 2))
    add(
        paragraph(
            "AMDGT gốc khai thác hai nhóm thông tin chính: similarity view và association view. Similarity view dùng graph transformer trên đồ thị tương đồng thuốc-thuốc và bệnh-bệnh. Association view dùng Heterogeneous Graph Transformer trên mạng gồm thuốc, bệnh và protein. Hai nguồn biểu diễn được kết hợp để dự đoán liên kết Thuốc - Bệnh bằng đầu phân loại nhị phân."
        )
    )
    add(heading("2.3. Các cải tiến đã triển khai trong dự án", 2))
    add(
        table(
            [
                ["Nhóm cải tiến", "Module / file", "Mô tả đúng theo code"],
                [
                    "Topology view",
                    "topology_features.py, topology_encoder.py",
                    "Trích degree, weighted degree, clustering, PageRank, average neighbor degree và degree theo association; sau đó mã hóa thành embedding.",
                ],
                [
                    "Multi-view contrastive learning",
                    "contrastive_loss.py",
                    "Đồng bộ biểu diễn giữa similarity view, association view và topology view bằng contrastive loss.",
                ],
                ["Multi-view aggregation", "multi_view_aggregator.py", "Dùng TransformerEncoder để hợp nhất ba view thành biểu diễn node cuối."],
                ["Fuzzy gate", "fuzzy_attention.py", "Điều tiết mức đóng góp của topology vào biểu diễn nền ở chế độ fusion rvg."],
                ["Similarity view fusion", "similarity_view_fusion.py", "Hỗ trợ học trọng số khi dùng nhiều graph similarity riêng biệt."],
                [
                    "Pair scoring",
                    "ReferencePairHead, InteractionPairHead",
                    "Ngoài Hadamard product + MLP, có thể dùng drug, disease, tích, hiệu tuyệt đối, cosine và bilinear.",
                ],
                [
                    "Training pipeline",
                    "train_final.py",
                    "Preset theo dataset, class weighting, focal/ranking loss, EMA, scheduler, early stopping và checkpoint.",
                ],
            ]
        )
    )
    add(heading("2.4. Diễn đạt thận trọng về RLG-HGT", 2))
    add(
        paragraph(
            "Trong báo cáo cũ, phần RLG-HGT được mô tả như một kiến trúc relation-level gating/metapath hoàn chỉnh. Khi đối chiếu với mã nguồn hiện tại, module rlg_hgt.py chủ yếu triển khai HGTConv nhiều lớp kèm residual connection, LayerNorm và LayerAggregator. Vì vậy, báo cáo chỉnh sửa trình bày RLG-HGT như một backbone mở rộng đang được chuẩn bị cho thí nghiệm, không khẳng định đầy đủ các nhánh metapath hoặc relation-level gating nếu chưa có hiện thực tương ứng trong code."
        )
    )
    add(heading("2.5. Quy trình huấn luyện", 2))
    for idx, item in enumerate(
        [
            "Đọc dữ liệu từ AMDGT/data/<dataset> gồm ma trận similarity, đặc trưng thuốc/bệnh/protein và các quan hệ đã biết.",
            "Tạo mẫu dương/âm cho bài toán dự đoán liên kết và chia k-fold cross-validation.",
            "Xây dựng graph similarity và heterograph Thuốc - Bệnh - Protein.",
            "Trích topology feature cho drug và disease, sau đó đưa qua TopologyEncoder.",
            "Huấn luyện mô hình với loss phân loại kết hợp contrastive loss và các cơ chế ổn định như scheduler, early stopping, checkpoint.",
            "Đánh giá bằng AUC, AUPR, Accuracy, Precision, Recall, F1-score và MCC.",
        ],
        1,
    ):
        add(numbered(item, idx))
    add(page_break())

    add(heading("CHƯƠNG 3. PHÂN TÍCH, THIẾT KẾ VÀ TRIỂN KHAI HỆ THỐNG", 1))
    add(heading("3.1. Kiến trúc tổng thể", 2))
    add(
        paragraph(
            "Hệ thống được thiết kế theo mô hình nhiều tầng. Người dùng thao tác trên website PHP; website gọi FastAPI để thực hiện suy luận; FastAPI nạp dữ liệu, graph và checkpoint mô hình; MySQL lưu thông tin tài khoản, dữ liệu quản trị và lịch sử dự đoán."
        )
    )
    add(
        table(
            [
                ["Tầng", "Thành phần", "Chức năng"],
                ["Presentation Layer", "public/*.php, public/assets/style.css", "Đăng nhập, dashboard dự đoán, kết quả, lịch sử, quản trị"],
                ["Application Layer", "AuthService.php, PredictionService.php", "Xác thực, session, gọi API, lưu lịch sử"],
                ["AI Service Layer", "python_api/main.py", "Load dataset/model, fuzzy match, scoring, trả JSON và graph"],
                ["Data Layer", "MySQL + AMDGT/data", "Lưu quản trị/lịch sử và cung cấp dữ liệu CSV cho mô hình"],
            ]
        )
    )
    add(heading("3.2. Cơ sở dữ liệu", 2))
    add(
        paragraph(
            "Schema MySQL được định nghĩa trong database/database_schema.sql. Các bảng được chia thành bốn nhóm: người dùng, thực thể sinh học, liên kết sinh học và lịch sử dự đoán."
        )
    )
    add(
        table(
            [
                ["Nhóm bảng", "Bảng", "Vai trò"],
                ["Người dùng", "users", "Tài khoản, vai trò admin/user, trạng thái và thời gian đăng nhập"],
                ["Thực thể", "drugs, diseases, proteins", "Lưu mã nguồn, tên, mô tả, SMILES/sequence nếu có"],
                ["Liên kết", "drug_disease_links, drug_protein_links, protein_disease_links", "Lưu quan hệ sinh học đã biết hoặc được quản trị bổ sung"],
                ["Lịch sử", "prediction_requests, prediction_results", "Lưu truy vấn dự đoán và từng kết quả Top-K"],
                ["Cấu hình", "system_settings", "Lưu cấu hình ứng dụng như endpoint API hoặc dataset mặc định"],
            ]
        )
    )
    add(heading("3.3. Python FastAPI", 2))
    add(
        paragraph(
            "FastAPI đóng vai trò bridge giữa mô hình học sâu và website. Lớp InferenceManager trong python_api/main.py chịu trách nhiệm nạp dữ liệu, cache context, tìm checkpoint, xây graph, xử lý metadata và tính điểm cho các cặp thuốc-bệnh."
        )
    )
    add(
        table(
            [
                ["Endpoint", "Chức năng"],
                ["/health", "Kiểm tra API online và device đang dùng"],
                ["/entities", "Trả danh sách thuốc/bệnh theo dataset để frontend chọn"],
                ["/predict", "Dự đoán một chiều: thuốc sang bệnh hoặc bệnh sang thuốc, trả Top-K và graph"],
                ["/predict_pairs", "Chấm ma trận tối đa 5 thuốc x 5 bệnh, trả điểm cải tiến/gốc nếu có checkpoint"],
            ]
        )
    )
    add(
        paragraph(
            "Lưu ý triển khai: nếu chưa tìm thấy checkpoint phù hợp, API có thể trả kết quả ở chế độ demo. Khi nộp hoặc trình diễn bản cuối, nhóm cần đảm bảo checkpoint thật đã được đặt đúng cấu trúc thư mục Result/original hoặc Result/improved để điểm dự đoán là kết quả suy luận thực."
        )
    )
    add(heading("3.4. Website PHP", 2))
    for item in [
        "Trang đăng nhập sử dụng AuthService và password_verify để xác thực tài khoản.",
        "Dashboard cho phép chọn dataset B/C/F, chọn tối đa 5 thuốc và 5 bệnh, đặt Top-K và gửi yêu cầu dự đoán.",
        "Khi chỉ chọn một phía, hệ thống trả danh sách Top-K theo hướng Thuốc -> Bệnh hoặc Bệnh -> Thuốc.",
        "Khi chọn cả thuốc và bệnh, hệ thống chấm toàn bộ ma trận cặp và so sánh điểm mô hình cải tiến với mô hình gốc nếu checkpoint gốc tồn tại.",
        "Trang lịch sử hiển thị các prediction_requests của người dùng hiện tại.",
        "Trang quản trị cho phép xem thống kê, quản lý thuốc, bệnh và liên kết drug-disease.",
    ]:
        add(bullet(item))
    add(heading("3.5. Luồng xử lý dự đoán", 2))
    for idx, item in enumerate(
        [
            "Người dùng đăng nhập và chọn dataset, thuốc/bệnh, Top-K.",
            "PHP kiểm tra CSRF, trạng thái API và gọi PredictionService.",
            "PredictionService gửi JSON đến FastAPI qua cURL.",
            "FastAPI fuzzy match đầu vào, tạo danh sách cặp cần chấm và gọi mô hình.",
            "API trả results, improved_score, original_score nếu có, note và dữ liệu graph.",
            "PHP render bảng kết quả, biểu đồ so sánh, graph 2D/3D và lưu lịch sử nếu là truy vấn đơn.",
        ],
        1,
    ):
        add(numbered(item, idx))
    add(page_break())

    add(heading("CHƯƠNG 4. THỰC NGHIỆM, KẾT QUẢ VÀ SẢN PHẨM MINH HỌA", 1))
    add(heading("4.1. Bộ dữ liệu sử dụng", 2))
    add(
        table(
            [
                ["Dataset", "Số thuốc", "Số bệnh", "Số protein", "Số liên kết Thuốc - Bệnh đã biết"],
                ["B-dataset", "269", "598", "1021", "18416"],
                ["C-dataset", "663", "409", "993", "2532"],
                ["F-dataset", "592", "313", "2741", "1933"],
            ]
        )
    )
    add(
        paragraph(
            "Ba dataset có quy mô và mức độ thưa khác nhau, giúp đánh giá mô hình trên nhiều bối cảnh. B-dataset có nhiều bệnh và nhiều liên kết đã biết; C-dataset là tập chuẩn thường dùng để đối chiếu; F-dataset có số protein lớn và dữ liệu liên kết thưa hơn."
        )
    )
    add(heading("4.2. Cấu hình thực nghiệm", 2))
    add(
        table(
            [
                ["Dataset", "Learning rate", "Neighbor", "GT dim / HGT dim", "Ghi chú"],
                ["B-dataset", "1e-4", "3", "512", "Preset ưu tiên biểu diễn lớn hơn cho tập có nhiều bệnh"],
                ["C-dataset", "1e-4", "5", "256", "Preset cân bằng, dùng mặc định cho demo"],
                ["F-dataset", "8e-5", "10", "384", "Bật multi-view similarity và positive weighting dạng global_log"],
            ]
        )
    )
    add(
        paragraph(
            "Các preset trên được định nghĩa trong train_final.py. Pipeline hỗ trợ 10-fold cross-validation, checkpoint theo fold, CSV kết quả, early stopping theo AUC và các metric AUC, AUPR, Accuracy, Precision, Recall, F1-score, MCC."
        )
    )
    add(heading("4.3. Kết quả thực nghiệm ghi nhận", 2))
    add(
        paragraph(
            "Bảng dưới đây giữ lại các kết quả nhóm đã ghi nhận trong bản báo cáo thực nghiệm. Để báo cáo cuối có tính kiểm chứng cao hơn, nhóm cần lưu kèm file CSV/log huấn luyện hoặc ảnh chụp output train tương ứng với từng bảng."
        )
    )
    add(
        table(
            [
                ["Dataset", "Metric", "AMDGT gốc", "Mô hình cải tiến", "Chênh lệch"],
                ["B-dataset", "AUC", "0.9153 ± 0.0039", "0.9284 ± 0.0036", "+0.0131"],
                ["B-dataset", "AUPR", "0.9112 ± 0.0043", "0.9230 ± 0.0032", "+0.0118"],
                ["C-dataset", "AUC", "0.9665 ± 0.0077", "0.9708 ± 0.0060", "+0.0043"],
                ["C-dataset", "AUPR", "0.9682 ± 0.0073", "0.9731 ± 0.0062", "+0.0049"],
                ["F-dataset", "AUC", "0.9572 ± 0.0084", "0.9631 ± 0.0080", "+0.0059"],
                ["F-dataset", "AUPR", "0.9594 ± 0.0078", "0.9605 ± 0.0074", "+0.0011"],
            ]
        )
    )
    add(
        paragraph(
            "Nhìn chung, mô hình cải tiến có xu hướng tăng AUC/AUPR trên cả ba dataset. Mức tăng rõ nhất nằm ở B-dataset, trong khi C-dataset và F-dataset vốn đã có baseline cao nên biên cải thiện nhỏ hơn. Cách diễn đạt phù hợp là mô hình cải tiến cho kết quả tích cực và ổn định hơn trong các kết quả nhóm ghi nhận, thay vì khẳng định tuyệt đối nếu chưa đính kèm đầy đủ log chạy lại baseline trong cùng môi trường."
        )
    )
    add(heading("4.4. Kết quả triển khai website", 2))
    add(
        paragraph(
            "Bên cạnh kết quả mô hình, sản phẩm website là phần thể hiện rõ đóng góp ứng dụng của đồ án. Website giúp chuyển một mô hình nghiên cứu chạy bằng terminal thành hệ thống có giao diện, tài khoản, lịch sử, quản trị dữ liệu và trực quan hóa kết quả."
        )
    )
    add_image("image2.png", 15.5, 10.4, "Hình 4.1. Giao diện chọn dataset, thuốc, bệnh và cấu hình Top-K.", 2)
    add_image(
        "image5.png",
        15.5,
        10.5,
        "Hình 4.2. Bảng delta so sánh điểm mô hình gốc và mô hình cải tiến theo từng cặp thuốc-bệnh.",
        3,
    )
    add_image("image6.png", 15.5, 10.4, "Hình 4.3. Biểu đồ cột và trực quan hóa mạng liên kết thuốc - protein - bệnh.", 4)
    add(heading("4.5. Hạn chế và hướng phát triển", 2))
    for item in [
        "Checkpoint và log huấn luyện kích thước lớn cần được lưu/đính kèm riêng để chứng minh kết quả định lượng khi nộp bản cuối.",
        "Một số nhánh mở rộng như metapath/relation-level gating cần tiếp tục hiện thực đầy đủ nếu muốn trình bày như đóng góp hoàn chỉnh.",
        "API có chế độ demo fallback khi thiếu checkpoint; khi triển khai thật cần tắt hoặc phân biệt rõ chế độ demo và chế độ suy luận thật.",
        "Website phục vụ tốt cho mục tiêu demo, nhưng còn có thể cải thiện bảo mật, phân trang dữ liệu lớn, logging lỗi và trải nghiệm trên thiết bị nhỏ.",
        "Kết quả dự đoán chỉ là gợi ý nghiên cứu, chưa thay thế đánh giá y sinh hoặc thử nghiệm lâm sàng.",
    ]:
        add(bullet(item))
    add(heading("4.6. Kết luận chương", 2))
    add(
        paragraph(
            "Chương này đã trình bày dữ liệu, cấu hình thực nghiệm, kết quả ghi nhận và phần sản phẩm web. Giá trị của đồ án nằm ở cả hai phía: cải tiến pipeline/model so với AMDGT gốc và đóng gói thành hệ thống có thể demo. Đây là hướng phát triển phù hợp với đồ án cơ sở vì vừa thể hiện năng lực nghiên cứu thuật toán, vừa thể hiện năng lực xây dựng phần mềm ứng dụng."
        )
    )
    add(page_break())

    add(heading("KẾT LUẬN", 1))
    add(
        paragraph(
            "Đồ án đã xây dựng một hệ thống dự đoán liên kết Thuốc - Bệnh dựa trên mạng Thuốc - Protein - Bệnh. So với repo AMDGT gốc, dự án mở rộng theo hai hướng: cải tiến pipeline/model bằng topology view, contrastive learning, multi-view aggregation và training pipeline ổn định hơn; đồng thời xây dựng hệ thống web/API/database để phục vụ người dùng cuối."
        )
    )
    add(
        paragraph(
            "Trong các kết quả nhóm ghi nhận, mô hình cải tiến cho xu hướng tăng AUC/AUPR so với baseline. Tuy nhiên, để bản báo cáo cuối có tính thuyết phục cao, nhóm cần lưu đầy đủ file kết quả huấn luyện, checkpoint và điều kiện môi trường chạy. Về mặt sản phẩm, website đã có các chức năng cốt lõi như đăng nhập, dự đoán Top-K, so sánh mô hình, trực quan hóa graph, lịch sử và quản trị dữ liệu."
        )
    )
    add(page_break())

    add(heading("TÀI LIỆU THAM KHẢO", 1))
    refs = [
        "PREDICT: a method for inferring novel drug indications with application to personalized medicine. https://doi.org/10.1038/msb.2011.26",
        "MRDDA: a multi-relational graph neural network for drug-disease association prediction. https://doi.org/10.1186/s12967-025-06783-x",
        "DD-HGNN+: Drug-disease Association Prediction via General Hypergraph Neural Network with Hierarchical Contrastive Learning and Cross Attention Learning. https://doi.org/10.1109/JBHI.2025.3542784",
        "Predicting drug-disease associations through layer attention graph convolutional network (LAGCN). https://doi.org/10.1093/bib/bbaa243",
        "AMDGT: Attention aware multi-modal fusion using a dual graph transformer for drug-disease associations prediction.",
        "Drug-Disease Association Prediction Using Heterogeneous Networks for Computational Drug Repositioning. https://doi.org/10.3390/biom12101497",
        "HGTDR: Advancing drug repurposing with heterogeneous graph transformers. https://doi.org/10.1093/bioinformatics/btae349",
        "JK-Liu7, AMDGT code repository. https://github.com/JK-Liu7/AMDGT",
        "DGL: Deep Graph Library. https://github.com/dmlc/dgl",
        "FastAPI framework. https://github.com/fastapi/fastapi",
        "3D Force Graph. https://github.com/vasturiano/3d-force-graph",
    ]
    for idx, ref in enumerate(refs, 1):
        add(paragraph(f"[{idx}] {ref}", spacing_after=80))

    return blocks


def build_document(src: Path, dst: Path) -> None:
    old_sect, image_rids = read_template_parts(src)
    document = ET.Element(qn(W, "document"))
    body = ET.SubElement(document, qn(W, "body"))
    for block in build_blocks(image_rids):
        body.append(block)
    body.append(old_sect if old_sect is not None else w_el("sectPr"))

    new_doc_xml = ET.tostring(document, encoding="utf-8", xml_declaration=True)

    with zipfile.ZipFile(src, "r") as zin, zipfile.ZipFile(dst, "w", zipfile.ZIP_DEFLATED) as zout:
        for item in zin.infolist():
            if item.filename == "word/document.xml":
                continue
            zout.writestr(item, zin.read(item.filename))
        zout.writestr("word/document.xml", new_doc_xml)


def main() -> None:
    if len(sys.argv) != 3:
        raise SystemExit("Usage: python scripts/build_revised_report_docx.py <source.docx> <output.docx>")
    src = Path(sys.argv[1])
    dst = Path(sys.argv[2])
    build_document(src, dst)
    print(dst.resolve())


if __name__ == "__main__":
    main()
