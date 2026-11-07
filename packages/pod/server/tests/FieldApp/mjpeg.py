import io, sys
from PIL import Image
qr = Image.open(sys.argv[1]).convert('RGB').resize((380, 380), Image.NEAREST)
with open(sys.argv[2], 'wb') as out:
    for _ in range(30):
        frame = Image.new('RGB', (640, 480), (205, 200, 190))
        frame.paste(qr, (130, 50))
        buf = io.BytesIO()
        frame.save(buf, 'JPEG', quality=90)
        out.write(buf.getvalue())
