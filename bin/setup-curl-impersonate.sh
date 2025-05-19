mkdir ~/curl-impersonate/ || true
cd ~/curl-impersonate/

wget https://github.com/lwthiker/curl-impersonate/releases/download/v0.6.1/libcurl-impersonate-v0.6.1.x86_64-linux-gnu.tar.gz
tar -xf libcurl-impersonate-v0.6.1.x86_64-linux-gnu.tar.gz
patchelf --set-soname libcurl.so.4 ~/curl-impersonate/libcurl-impersonate-chrome.so
