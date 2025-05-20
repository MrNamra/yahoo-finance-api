mkdir /home/runner/curl-impersonate/ || true
cd /home/runner/curl-impersonate/

wget https://github.com/lwthiker/curl-impersonate/releases/download/v0.6.1/libcurl-impersonate-v0.6.1.x86_64-linux-gnu.tar.gz
tar -xf libcurl-impersonate-v0.6.1.x86_64-linux-gnu.tar.gz
patchelf --set-soname libcurl.so.4 /home/runner/curl-impersonate/libcurl-impersonate-chrome.so

ls -l /home/runner/curl-impersonate
