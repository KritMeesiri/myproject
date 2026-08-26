---------------------------------------------------------------------------------------------------
Post-Quantum Signcryption for IoT data stream in Hadoop system
---------------------------------------------------------------------------------------------------

This experiments was constructed and run in Ubuntu LTS 26.04

Two major packages used are:
Intel SGX SDK - A software development kit for Intel SGX TEE.
liboqs - An open-sourced C library of cryptographic algorithms and functions.
If any error regarding the installation or the existance of the mentioned packages, please refer to the offical installation instuction pages.

Intel SGX SDK: https://cc-enabling.trustedservices.intel.com/intel-sgx-sw-installation-guide-linux/02/installation_instructions/
liboqs: https://openquantumsafe.org/liboqs/getting-started.html

---------------------------------------------------------------------------------------------------
Main files/folders used in this demo:
---------------------------------------------------------------------------------------------------

demo.ipynb - Location: ./somchart/pq-sim/demo.ipynb
  This file is for the main pipeline demostration. This python notebook file contains the firsts three phases of the proposed scheme.
  Please ignore the other copies of demo.ipynb as those are decpreciated test versions and do not contribute to the project anymore.

SampleEnclave - Location: ./somchart/sgxsdk/SampleCode/SampleEnclave
  This is folder is the main TEE enclave implementation of Phase 4. Here contains the required file of run the Intel SGX.
  The code of the untrusted side is App.cpp, for the trusted side is Enclave.cpp.
  This folder is used in performance evaluation section of the paper.

SampleEnclave_Demo - Location: ./somchart/sgxsdk/SampleCode/SampleEnclave_demo
  This is folder is similar with the previosly mentioned. The key different is the inputs of TEE enclave.
  The purpose of this folder is for the system demostration.

pq_clscl.py - Location: ./somchart/python/.venv/pq_clscl.py
  This python file is the implementation of S. Xu et al., “Efficient Post-Quantum Certificateless Signcryption With Linkability,”
  This is used in the performance evaluation.

ntru_iblrscs.py - ./somchart/python/.venv/ntru_iblrscs.py
  This python file is the implementation of D. Sinha et al., “Post-Quantum Identity-Based Linkable Ring Signcryption for Edge IoT Devices,”.
  This is used in the performance evaluation.

vanet_ring_signcryption.py - Location: ./somchart/python/.venv/vanet_ring_signcryption.py
  This python file is the implementation of S. Prajapat et al., “Lattice-Based Ring Signcryption for 6G Vehicular Networks,”
  This is used the the performance evaluation.

convert_input.py - Location: /home/vboxuser/somchart/sgxsdk/SampleCode/SampleEnclave_Demo/App/convert_input.py
  This is a python utility file for converting the output of Kafka-Spark simulation, which output in pytho dictionary format, into binary file that is readable in C++.

beachmark.sh - Location: ./somchart/benchmark.sh
  This is a UNIX bash shellscript file for collecting performance data.


***** The following instruction assumed the working directory is ./somchart *****  

---------------------------------------------------------------------------------------------------
to run the benchmark
---------------------------------------------------------------------------------------------------

chmod +x benchmark.sh

./benchmark.sh \
  -n 4096 \       # bytes per record
  -b 50   \       # batch size (py scripts run 50×, cpp gets b=50)
  -m 10   \       # 10 repetitions
  -h              # to display options

---------------------------------------------------------------------------------------------------
to run the python files
---------------------------------------------------------------------------------------------------
- for pq_clscl.py, ntru_iblrscs.py, vanet_ring_signcryption.py -

source ./python/.venv/bin/environment
python <filename>

- for demo.ipynb - 

use the code in vscode with jupyter extention.
select the kernel to be the virtual environment one.

---------------------------------------------------------------------------------------------------
to run the App.cpp in SampleEnclave and SampleEnclave_Demo
---------------------------------------------------------------------------------------------------

source ./somchart/sgxsdk/environment

cd ./somchart/sgxsdk/SampleCode/(SampleEnclave OR SampleEnclave_Demo)
make SGX_MODE=SIM
./app 

[OPTION] for SampleEnclave
./app b m
b - number of records (batch size)
m - number of message bytes

[OPTION] for SampleEnclave_Demo
./app <filepathname>

pathname for phase4_input.bin

Clear build with
make clean

---------------------------------------------------------------------------------------------------
to run the convert_input.py
---------------------------------------------------------------------------------------------------

# 1. Convert the notebook output to binary
python3 App/convert_input.py ~/somchart/pq-sim/Kafa-Spark_Output.txt phase4_input.bin
# → Written 252144 bytes  |  epoch_id=795424448  records=359  PolRef=POLICY_DEFAULT

# 2. Build (if not already built)
make SGX_MODE=SIM

# 3. Run with real data
./app phase4_input.bin
