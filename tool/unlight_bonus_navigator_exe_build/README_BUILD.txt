UNLIGHT Bonus Navigator - Windows EXE Build Kit
================================================

這個資料夾可在 Windows 11 上建立：
    dist\UnlightBonusNavigator.exe

必要檔案
--------
1. unlight_bonus_navigator.py
2. ul_sniffer.py
3. build_exe.bat
4. UnlightBonusNavigator.spec
5. requirements-build.txt
6. version_info.txt

重要
----
本套件沒有包含 ul_sniffer.py。
請把目前 detector 資料夾內「已經實測可用」的 ul_sniffer.py
複製到這個建置資料夾，再執行 build_exe.bat。

建置方式
--------
1. 將本資料夾解壓縮到 Windows。
2. 複製 ul_sniffer.py 到本資料夾。
3. 雙擊 build_exe.bat。
4. 完成後取得：
       dist\UnlightBonusNavigator.exe

建議
----
第一次先在自己的電腦測試 EXE：
1. 啟動帶有 CDP 59222 的 UNLIGHT 桌面版。
2. 進入獎勵遊戲。
3. 執行 UnlightBonusNavigator.exe。
4. 確認目標、位置、距離與自動停止都正常。

Windows SmartScreen
-------------------
未簽章的自行製作 EXE 可能顯示 SmartScreen 警告。
這不是程式碼錯誤。要對外大量發布，後續應考慮程式碼簽章。
