# github.com/pin/tftp/v3 v3.1.0 — SYD yaması

`go.mod`'daki `replace` bu kopyayı kullandırır. Özgünden TEK fark
`connection.go`'daki `chanConnection` bekleme süresidir (tek port kipi):
süre her okumada baştan sayılıyordu, artık `setDeadline`'dan itibaren mutlak.
Ayrıntı `chanConnection.wait` yorumunda; sınaması `../main_test.go`
(`TestLostBlockIsResent`). v3.2.0'da da aynı hata var — kütüphane
düzeltilince bu dizin ve `replace` satırı silinir.
