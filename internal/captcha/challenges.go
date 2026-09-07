package captcha

import (
	"bytes"
	"encoding/base64"
	"fmt"
	"image"
	"image/color"
	"image/draw"
	"image/png"
	"math"
	"strings"
)

// Original 5x7 raster glyph definitions; no system font or font download is used.
var glyphs = map[rune]string{
	'0': "01110100011001110101110011000101110", '1': "00100011000010000100001000010001110",
	'2': "01110100010000100010001000100011111", '3': "11110000010000101110000010000111110",
	'4': "00010001100101010010111110001000010", '5': "11111100001111000001000011000101110",
	'6': "00110010001000011110100011000101110", '7': "11111000010001000100010000100001000",
	'8': "01110100011000101110100011000101110", '9': "01110100011000101111000010001011100",
	'A': "01110100011000111111100011000110001", 'B': "11110100011000111110100011000111110",
	'C': "01111100001000010000100001000001111", 'D': "11110100011000110001100011000111110",
	'E': "11111100001000011110100001000011111", 'F': "11111100001000011110100001000010000",
	'G': "01111100001000010111100011000101111", 'H': "10001100011000111111100011000110001",
	'J': "00001000010000100001100011000101110", 'K': "10001100101010011000101001001010001",
	'M': "10001110111010110101100011000110001", 'N': "10001110011010110011100011000110001",
	'P': "11110100011000111110100001000010000", 'Q': "01110100011000110001101011001001101",
	'R': "11110100011000111110101001001010001", 'S': "01111100001000001110000010000111110",
	'T': "11111001000010000100001000010000100", 'U': "10001100011000110001100011000101110",
	'V': "10001100011000110001100010101000100", 'W': "10001100011000110101101011101110001",
	'X': "10001100010101000100010101000110001", 'Y': "10001100010101000100001000010000100",
	'Z': "11111000010001000100010001000011111",
}

func pngData(img image.Image) string {
	var b bytes.Buffer
	_ = png.Encode(&b, img)
	return "data:image/png;base64," + base64.StdEncoding.EncodeToString(b.Bytes())
}
func circle(im *image.RGBA, cx, cy, r int, c color.RGBA) {
	for y := cy - r; y <= cy+r; y++ {
		for x := cx - r; x <= cx+r; x++ {
			if (x-cx)*(x-cx)+(y-cy)*(y-cy) <= r*r {
				im.SetRGBA(x, y, c)
			}
		}
	}
}
func rasterText(im *image.RGBA, text string, x, y, scale int, ink color.RGBA, phase float64) {
	for i, r := range text {
		g := glyphs[r]
		for j, v := range g {
			if v != '1' {
				continue
			}
			for dy := 0; dy < scale; dy++ {
				for dx := 0; dx < scale; dx++ {
					yy := y + (j/5)*scale + dy
					xx := x + i*6*scale + (j%5)*scale + dx + int(3*math.Sin(float64(yy)/10+phase))
					im.SetRGBA(xx, yy, ink)
				}
			}
		}
	}
}
func tokenText(n int, alphabet string) (string, error) {
	var b strings.Builder
	for range n {
		i, e := randomInt(len(alphabet))
		if e != nil {
			return "", e
		}
		b.WriteByte(alphabet[i])
	}
	return b.String(), nil
}
func background(w, h int) (*image.RGBA, error) {
	im := image.NewRGBA(image.Rect(0, 0, w, h))
	shift, e := randomInt(90)
	if e != nil {
		return nil, e
	}
	for y := 0; y < h; y++ {
		for x := 0; x < w; x++ {
			wave := int(14 * math.Sin(float64(x+y)/21))
			im.SetRGBA(x, y, color.RGBA{uint8(25 + x/4 + wave), uint8(80 + y/3 + shift), uint8(150 + x/6), 255})
		}
	}
	for range 18 {
		x, e := randomInt(w)
		if e != nil {
			return nil, e
		}
		y, e := randomInt(h)
		if e != nil {
			return nil, e
		}
		r, e := randomInt(17)
		if e != nil {
			return nil, e
		}
		circle(im, x, y, r+5, color.RGBA{uint8(120 + x/3), uint8(190 + y/5), uint8(210 - y/4), 255})
	}
	return im, nil
}
func generate(kind string, hard bool) (Challenge, Answer, error) {
	id, err := randomToken()
	if err != nil {
		return Challenge{}, Answer{}, err
	}
	c := Challenge{ID: id, Type: kind}
	a := Answer{}
	switch kind {
	case "text":
		a.Text, err = tokenText(5, "23456789ABCDEFGHJKMNPQRSTUVWXYZ")
		if err != nil {
			break
		}
		im := image.NewRGBA(image.Rect(0, 0, 300, 100))
		draw.Draw(im, im.Bounds(), image.NewUniform(color.RGBA{238, 244, 251, 255}), image.Point{}, draw.Src)
		for range 80 {
			x, er := randomInt(300)
			if er != nil {
				err = er
				break
			}
			y, er := randomInt(100)
			if er != nil {
				err = er
				break
			}
			circle(im, x, y, 1, color.RGBA{170, 185, 202, 255})
		}
		if err != nil {
			break
		}
		for i, r := range a.Text {
			j, er := randomInt(12)
			if er != nil {
				err = er
				break
			}
			phase := float64(j)
			rasterText(im, string(r), 23+i*52, 26+j/2, 6, color.RGBA{22, 44, uint8(62 + j*2), 255}, phase)
		}
		if hard {
			for y := 12; y < 90; y += 19 {
				for x := 0; x < 300; x++ {
					im.SetRGBA(x, y+int(5*math.Sin(float64(x)/21)), color.RGBA{92, 121, 153, 255})
				}
			}
		}
		c.Image = pngData(im)
		c.Prompt = "text"
		c.Width = 300
		c.Height = 100
	case "slider", "drag":
		im, er := background(300, 160)
		if er != nil {
			err = er
			break
		}
		x, er := randomInt(160)
		if er != nil {
			err = er
			break
		}
		x += 65
		y := 58
		if kind == "drag" {
			yy, er := randomInt(76)
			if er != nil {
				err = er
				break
			}
			y = 20 + yy
		}
		const size = 44
		piece := image.NewRGBA(image.Rect(0, 0, size, size))
		for py := 0; py < size; py++ {
			for px := 0; px < size; px++ {
				inside := px >= 6 && px < 38 && py >= 6 && py < 38
				inside = inside || (px-22)*(px-22)+(py-5)*(py-5) < 49
				inside = inside || (px-38)*(px-38)+(py-22)*(py-22) < 36
				if !inside {
					continue
				}
				piece.Set(px, py, im.At(x+px, y+py))
				im.SetRGBA(x+px, y+py, color.RGBA{12, 26, 48, 255})
			}
		}
		c.Image = pngData(im)
		c.Piece = pngData(piece)
		c.Width = 300
		c.Height = 160
		c.PieceSize = size
		if kind == "slider" {
			c.PieceTop = y
		}
		c.Prompt = kind
		a.X = float64(x)
		a.Y = float64(y)
	case "rotate":
		angle, er := randomInt(330)
		if er != nil {
			err = er
			break
		}
		angle += 15
		im := image.NewRGBA(image.Rect(0, 0, 180, 180))
		circle(im, 90, 90, 86, color.RGBA{233, 241, 250, 255})
		points := []Point{{90, 23}, {124, 69}, {103, 69}, {103, 143}, {77, 143}, {77, 69}, {56, 69}}
		rad := float64(angle) * math.Pi / 180
		for i, p := range points {
			dx, dy := p.X-90, p.Y-90
			points[i] = Point{90 + dx*math.Cos(rad) - dy*math.Sin(rad), 90 + dx*math.Sin(rad) + dy*math.Cos(rad)}
		}
		for y := 0; y < 180; y++ {
			for x := 0; x < 180; x++ {
				inside := false
				j := len(points) - 1
				for i, p := range points {
					q := points[j]
					if (p.Y > float64(y)) != (q.Y > float64(y)) && float64(x) < (q.X-p.X)*(float64(y)-p.Y)/(q.Y-p.Y)+p.X {
						inside = !inside
					}
					j = i
				}
				if inside {
					im.SetRGBA(x, y, color.RGBA{29, 80, 143, 255})
				}
			}
		}
		c.Image = pngData(im)
		c.Width = 180
		c.Height = 180
		c.Prompt = "rotate"
		a.Angle = float64((360 - angle) % 360)
	case "click":
		im, er := background(300, 160)
		if er != nil {
			err = er
			break
		}
		labels := []rune("123456789")
		for i := len(labels) - 1; i > 0; i-- {
			j, er := randomInt(i + 1)
			if er != nil {
				err = er
				break
			}
			labels[i], labels[j] = labels[j], labels[i]
		}
		if err != nil {
			break
		}
		for i, r := range labels {
			x := 50 + (i%3)*100
			y := 27 + (i/3)*52
			circle(im, x, y, 21, color.RGBA{244, 247, 253, 255})
			rasterText(im, string(r), x-8, y-11, 3, color.RGBA{24, 45, 73, 255}, 0)
			if r <= '3' {
				for len(a.Points) < 3 {
					a.Points = append(a.Points, Point{})
				}
				a.Points[int(r-'1')] = Point{float64(x), float64(y)}
			}
		}
		c.Image = pngData(im)
		c.Width = 300
		c.Height = 160
		c.Prompt = "click"
	case "math", "logic":
		x, er := randomInt(18)
		if er != nil {
			err = er
			break
		}
		y, er := randomInt(9)
		if er != nil {
			err = er
			break
		}
		x += 2
		y += 2
		if kind == "math" {
			c.Prompt = fmt.Sprintf("%d + %d = ?", x, y)
			a.Text = fmt.Sprint(x + y)
		} else {
			c.Prompt = fmt.Sprintf("%d, %d, %d, ?", x, x+y, x+2*y)
			a.Text = fmt.Sprint(x + 3*y)
		}
	case "audio":
		a.Text, err = tokenText(5, "0123456789")
		if err == nil {
			c.Audio, err = audioData(a.Text)
		}
		c.Prompt = "audio"
	case "pow":
		c.Prompt = "pow"
	default:
		err = fmt.Errorf("unsupported challenge %q", kind)
	}
	return c, a, err
}
