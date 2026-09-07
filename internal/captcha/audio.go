package captcha

import (
	"bytes"
	"compress/gzip"
	"encoding/base64"
	"encoding/binary"
	"encoding/json"
	"errors"
	"io"
	"sync"
)

var audioOnce sync.Once
var samples map[string][]byte
var audioErr error

// digitAudioGzip is generated speech, not the synthesizer or a font. Its source
// generation recipe is scripts/audio-pack.py. Audio uses no runtime TTS service.
func audioData(text string) (string, error) {
	audioOnce.Do(func() {
		b, e := base64.StdEncoding.DecodeString(digitAudioGzip)
		if e != nil {
			audioErr = e
			return
		}
		z, e := gzip.NewReader(bytes.NewReader(b))
		if e != nil {
			audioErr = e
			return
		}
		defer z.Close()
		d := json.NewDecoder(io.LimitReader(z, 300000))
		audioErr = d.Decode(&samples)
	})
	if audioErr != nil {
		return "", audioErr
	}
	var pcm bytes.Buffer
	silence := bytes.Repeat([]byte{128}, 1000)
	pcm.Write(silence)
	for _, r := range text {
		sample, ok := samples[string(r)]
		if !ok {
			return "", errors.New("audio asset missing")
		}
		pcm.Write(sample)
		pcm.Write(silence)
	}
	var wav bytes.Buffer
	wav.WriteString("RIFF")
	binary.Write(&wav, binary.LittleEndian, uint32(36+pcm.Len()))
	wav.WriteString("WAVEfmt ")
	binary.Write(&wav, binary.LittleEndian, uint32(16))
	binary.Write(&wav, binary.LittleEndian, uint16(1))
	binary.Write(&wav, binary.LittleEndian, uint16(1))
	binary.Write(&wav, binary.LittleEndian, uint32(8000))
	binary.Write(&wav, binary.LittleEndian, uint32(8000))
	binary.Write(&wav, binary.LittleEndian, uint16(1))
	binary.Write(&wav, binary.LittleEndian, uint16(8))
	wav.WriteString("data")
	binary.Write(&wav, binary.LittleEndian, uint32(pcm.Len()))
	wav.Write(pcm.Bytes())
	return "data:audio/wav;base64," + base64.StdEncoding.EncodeToString(wav.Bytes()), nil
}
