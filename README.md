# remotepdf


## docker setup


    ````
    apt install chromium
    ./tm configuration --section browsershot --key noSandbox --value 1
    ./tm configuration --section browsershot --key chrome_path --value $(which chromium)
    ````
