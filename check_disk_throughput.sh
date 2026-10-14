# sudo apt update
# sudo apt install -y fio

fio --name=vod-read \
    --filename=/home/test-file.bin \
    --size=4G \
    --rw=read \
    --bs=1M \
    --direct=1 \
    --ioengine=libaio \
    --iodepth=16 \
    --numjobs=1 \
    --runtime=30 \
    --time_based \
    --group_reporting
